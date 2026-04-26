<?php

namespace App\Services;

use App\Models\AccessPoint;
use App\Models\BillingLedgerEntry;
use App\Models\Operator;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccessPointBillingService
{
    public const POST_HEARTBEAT_CACHE_KEY = 'billing:access-points:post-heartbeat-at';

    public const BILLING_BLOCK_HEALTH_AUTOMATION_DEGRADED = 'health_automation_degraded';
    public const BILLING_BLOCK_INVALID_OWNERSHIP = 'invalid_current_ownership';
    public const BILLING_BLOCK_STALE_HEALTH = 'stale_health_signal';
    public const BILLING_BLOCK_CONFIRMED_CONNECTION_PREDATES_OWNERSHIP = 'confirmed_connection_predates_trusted_ownership';
    public const BILLING_BLOCK_OWNERSHIP_CORRECTED_AFTER_BILLING = 'ownership_corrected_after_billing_requires_manual_resolution';

    public const RESOLUTION_ACTION_CONFIRM_ELIGIBILITY = 'confirm_eligibility';
    public const RESOLUTION_ACTION_AUTHORIZE_REPOST = 'authorize_repost';

    public function __construct(
        private readonly AccessPointHealthService $healthService,
    ) {
    }

    public function postConnectionFees(?User $actor = null, string $source = BillingLedgerEntry::SOURCE_AUTOMATION): array
    {
        $now = now();
        $this->notePostHeartbeat($now);
        $healthRuntime = $this->healthService->runtimeHealth();

        $posted = 0;
        $blocked = 0;
        $unbilled = 0;
        $alreadyBilled = 0;
        $reversed = 0;

        AccessPoint::query()
            ->where('adoption_state', AccessPoint::ADOPTION_STATE_ADOPTED)
            ->whereNotNull('claimed_by_operator_id')
            ->orderBy('id')
            ->chunkById(100, function ($accessPoints) use (
                $actor,
                $source,
                $healthRuntime,
                &$posted,
                &$blocked,
                &$unbilled,
                &$alreadyBilled,
                &$reversed,
            ): void {
                foreach ($accessPoints as $accessPoint) {
                    $result = $this->processAccessPoint($accessPoint->id, $actor, $source, $healthRuntime);

                    match ($result) {
                        'posted' => $posted++,
                        'blocked' => $blocked++,
                        'reversed' => $reversed++,
                        'already_billed' => $alreadyBilled++,
                        default => $unbilled++,
                    };
                }
            });

        return [
            'posted' => $posted,
            'blocked' => $blocked,
            'unbilled' => $unbilled,
            'already_billed' => $alreadyBilled,
            'reversed' => $reversed,
            'source' => $source,
        ];
    }

    public function reverseConnectionFee(AccessPoint $accessPoint, User $admin, string $reason, ?string $notes = null): BillingLedgerEntry
    {
        return DB::transaction(function () use ($accessPoint, $admin, $reason, $notes): BillingLedgerEntry {
            /** @var AccessPoint $lockedAccessPoint */
            $lockedAccessPoint = AccessPoint::query()
                ->with(['site', 'claimedByOperator'])
                ->lockForUpdate()
                ->findOrFail($accessPoint->id);

            $originalDebit = $this->latestActiveDebitForUpdate($lockedAccessPoint->id);

            if (! $originalDebit) {
                throw new RuntimeException('This access point has no posted AP connection fee to reverse.');
            }

            $credit = $this->createReversalEntry($lockedAccessPoint, $originalDebit, $admin, $reason, $notes);

            $this->syncAccessPointBillingState(
                $lockedAccessPoint,
                AccessPoint::BILLING_STATE_REVERSED,
                $originalDebit->posted_at,
                $lockedAccessPoint->billing_incident_state
                    ? $lockedAccessPoint->billing_block_reason
                    : $reason,
                $credit->id,
                null,
                false
            );

            return $credit->refresh();
        });
    }

    public function resolveBillingIncident(
        AccessPoint $accessPoint,
        User $admin,
        string $action,
        string $reason,
        ?string $notes = null,
    ): AccessPoint {
        return DB::transaction(function () use ($accessPoint, $admin, $action, $reason, $notes): AccessPoint {
            /** @var AccessPoint $lockedAccessPoint */
            $lockedAccessPoint = AccessPoint::query()
                ->with(['site.operator', 'approvedClaim', 'latestBillingEntry', 'claimedByOperator'])
                ->lockForUpdate()
                ->findOrFail($accessPoint->id);

            $currentGenerationDebit = $this->currentGenerationDebitForUpdate($lockedAccessPoint);
            $latestActiveDebit = $this->latestActiveDebitForUpdate($lockedAccessPoint->id);
            $healthRuntime = $this->healthService->runtimeHealth();
            $now = now();

            return match ($action) {
                self::RESOLUTION_ACTION_CONFIRM_ELIGIBILITY => $this->resolvePredatesOwnershipIncident(
                    $lockedAccessPoint,
                    $admin,
                    $reason,
                    $notes,
                    $healthRuntime,
                    $currentGenerationDebit,
                    $now
                ),
                self::RESOLUTION_ACTION_AUTHORIZE_REPOST => $this->resolveCorrectedOwnershipIncident(
                    $lockedAccessPoint,
                    $admin,
                    $reason,
                    $notes,
                    $healthRuntime,
                    $latestActiveDebit,
                    $now
                ),
                default => throw new RuntimeException('Unknown billing incident resolution action.'),
            };
        });
    }

    public function runtimeHealth(): array
    {
        $candidateCount = AccessPoint::query()
            ->where('adoption_state', AccessPoint::ADOPTION_STATE_ADOPTED)
            ->whereNotNull('claimed_by_operator_id')
            ->whereNotNull('first_confirmed_connected_at')
            ->whereNotIn('billing_state', [
                AccessPoint::BILLING_STATE_BILLED,
                AccessPoint::BILLING_STATE_REVERSED,
            ])
            ->count();

        $blockedByAutomationCount = AccessPoint::query()
            ->where('billing_state', AccessPoint::BILLING_STATE_BLOCKED)
            ->where('billing_incident_state', AccessPoint::BILLING_INCIDENT_AUTOMATION_DEGRADED)
            ->count();

        $blockedIncidentCount = AccessPoint::query()
            ->where('billing_state', AccessPoint::BILLING_STATE_BLOCKED)
            ->whereNotNull('billing_incident_state')
            ->count();

        $manualReviewCount = AccessPoint::query()
            ->where('billing_incident_state', AccessPoint::BILLING_INCIDENT_MANUAL_REVIEW_REQUIRED)
            ->count();

        $postHeartbeat = $this->parseHeartbeat(Cache::get(self::POST_HEARTBEAT_CACHE_KEY));
        $degradedThreshold = now()->subSeconds($this->billingRuntimeDegradedAfterSeconds());
        $postingDegraded = $candidateCount > 0 && (! $postHeartbeat || $postHeartbeat->lt($degradedThreshold));
        $healthRuntime = $this->healthService->runtimeHealth();

        return [
            'post_heartbeat_at' => $postHeartbeat?->toDateTimeString(),
            'candidate_count' => $candidateCount,
            'blocked_by_automation_count' => $blockedByAutomationCount,
            'blocked_incident_count' => $blockedIncidentCount,
            'manual_review_count' => $manualReviewCount,
            'posting_degraded' => $postingDegraded,
            'degraded' => $postingDegraded || $healthRuntime['degraded'],
        ];
    }

    public function present(AccessPoint $accessPoint): array
    {
        $latestEntry = $accessPoint->relationLoaded('latestBillingEntry')
            ? $accessPoint->latestBillingEntry
            : $accessPoint->latestBillingEntry()->first();

        $incidentState = $accessPoint->billing_incident_state;
        $availableActions = $this->availableResolutionActions($accessPoint);

        return [
            'billing_state' => $accessPoint->billing_state ?? AccessPoint::BILLING_STATE_UNBILLED,
            'billing_label' => $this->billingLabel($accessPoint->billing_state),
            'billing_posted_at' => optional($accessPoint->billing_posted_at)?->toDateTimeString(),
            'billing_block_reason' => $accessPoint->billing_block_reason,
            'billing_incident_state' => $incidentState,
            'billing_incident_label' => $this->billingIncidentLabel($incidentState),
            'billing_incident_opened_at' => optional($accessPoint->billing_incident_opened_at)?->toDateTimeString(),
            'billing_incident_resolved_at' => optional($accessPoint->billing_incident_resolved_at)?->toDateTimeString(),
            'billing_eligibility_confirmed_at' => optional($accessPoint->billing_eligibility_confirmed_at)?->toDateTimeString(),
            'latest_billing_resolution_reason' => $accessPoint->latest_billing_resolution_reason,
            'available_resolution_actions' => $availableActions,
            'eligible_for_manual_repost' => in_array(self::RESOLUTION_ACTION_AUTHORIZE_REPOST, $availableActions, true) === false
                && ($accessPoint->billing_charge_generation > 0)
                && ($accessPoint->billing_state === AccessPoint::BILLING_STATE_UNBILLED),
            'latest_entry' => $latestEntry ? [
                'id' => $latestEntry->id,
                'direction' => $latestEntry->direction,
                'amount' => number_format((float) $latestEntry->amount, 2, '.', ''),
                'currency' => $latestEntry->currency,
                'state' => $latestEntry->state,
                'posted_at' => optional($latestEntry->posted_at)?->toDateTimeString(),
            ] : null,
        ];
    }

    public function markOwnershipCorrectionRequiresManualResolution(AccessPoint $accessPoint): void
    {
        if (! $this->hasPostedDebit($accessPoint->id)) {
            return;
        }

        $now = now();

        $accessPoint->forceFill([
            'billing_state' => AccessPoint::BILLING_STATE_BLOCKED,
            'billing_block_reason' => self::BILLING_BLOCK_OWNERSHIP_CORRECTED_AFTER_BILLING,
            'billing_incident_state' => AccessPoint::BILLING_INCIDENT_CORRECTED_AFTER_BILLING,
            'billing_incident_opened_at' => $now,
            'billing_incident_resolved_at' => null,
            'latest_billing_resolution_reason' => $accessPoint->latest_correction_reason,
            'billing_resolution_metadata' => array_merge($accessPoint->billing_resolution_metadata ?? [], [
                'last_incident' => [
                    'state' => AccessPoint::BILLING_INCIDENT_CORRECTED_AFTER_BILLING,
                    'opened_at' => $now->toIso8601String(),
                    'reason' => $accessPoint->latest_correction_reason,
                ],
            ]),
        ])->save();
    }

    private function processAccessPoint(int $accessPointId, ?User $actor, string $source, array $healthRuntime): string
    {
        return DB::transaction(function () use ($accessPointId, $actor, $source, $healthRuntime): string {
            /** @var AccessPoint $accessPoint */
            $accessPoint = AccessPoint::query()
                ->with(['site.operator', 'approvedClaim', 'latestBillingEntry'])
                ->lockForUpdate()
                ->findOrFail($accessPointId);

            $currentDebit = $this->currentGenerationDebitForUpdate($accessPoint);
            $reversalEntry = $currentDebit?->reversalEntry()->lockForUpdate()->first();

            if ($currentDebit && $reversalEntry) {
                $this->syncAccessPointBillingState(
                    $accessPoint,
                    AccessPoint::BILLING_STATE_REVERSED,
                    $currentDebit->posted_at,
                    $accessPoint->billing_block_reason,
                    $reversalEntry->id,
                    null,
                    false
                );

                return 'reversed';
            }

            if ($currentDebit) {
                $postBillingIssue = $this->postBillingIssue($accessPoint, $currentDebit);

                if ($postBillingIssue) {
                    $this->openBillingIncident(
                        $accessPoint,
                        $postBillingIssue,
                        $currentDebit->posted_at,
                        $currentDebit->id
                    );

                    return 'blocked';
                }

                $this->syncAccessPointBillingState(
                    $accessPoint,
                    AccessPoint::BILLING_STATE_BILLED,
                    $currentDebit->posted_at,
                    null,
                    $currentDebit->id,
                    null,
                    true
                );

                return 'already_billed';
            }

            $eligibility = $this->evaluateEligibility($accessPoint, null, $healthRuntime);

            if ($eligibility['state'] === AccessPoint::BILLING_STATE_UNBILLED) {
                $this->syncAccessPointBillingState(
                    $accessPoint,
                    AccessPoint::BILLING_STATE_UNBILLED,
                    null,
                    null,
                    $accessPoint->latest_billing_entry_id,
                    null,
                    true
                );

                return 'unbilled';
            }

            if ($eligibility['state'] === AccessPoint::BILLING_STATE_BLOCKED) {
                $this->openBillingIncident(
                    $accessPoint,
                    $eligibility['reason'],
                    null,
                    $accessPoint->latest_billing_entry_id
                );

                return 'blocked';
            }

            $this->syncAccessPointBillingState(
                $accessPoint,
                AccessPoint::BILLING_STATE_PENDING_POST,
                null,
                null,
                $accessPoint->latest_billing_entry_id,
                null,
                true
            );

            try {
                $entry = BillingLedgerEntry::query()->create([
                    'operator_id' => $accessPoint->claimed_by_operator_id,
                    'site_id' => $accessPoint->site_id,
                    'access_point_id' => $accessPoint->id,
                    'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
                    'direction' => BillingLedgerEntry::DIRECTION_DEBIT,
                    'amount' => $this->connectionFeeAmount(),
                    'currency' => $this->connectionFeeCurrency(),
                    'state' => BillingLedgerEntry::STATE_POSTED,
                    'billable_key' => $this->connectionFeeBillableKey($accessPoint),
                    'triggered_at' => $this->billingTriggeredAt($accessPoint),
                    'posted_at' => now(),
                    'source' => $source,
                    'metadata' => [
                        'triggered_by_user_id' => $actor?->id,
                        'triggered_by_name' => $actor?->name,
                        'first_confirmed_connected_at' => optional($accessPoint->first_confirmed_connected_at)?->toIso8601String(),
                        'billing_eligibility_confirmed_at' => optional($accessPoint->billing_eligibility_confirmed_at)?->toIso8601String(),
                        'ownership_verified_at' => optional($this->trustedOwnershipAt($accessPoint))?->toIso8601String(),
                        'health_state' => $accessPoint->health_state,
                        'billing_charge_generation' => $accessPoint->billing_charge_generation,
                    ],
                ]);
            } catch (QueryException $exception) {
                $entry = BillingLedgerEntry::query()
                    ->where('billable_key', $this->connectionFeeBillableKey($accessPoint))
                    ->first();

                if (! $entry) {
                    throw $exception;
                }
            }

            $this->syncAccessPointBillingState(
                $accessPoint,
                AccessPoint::BILLING_STATE_BILLED,
                $entry->posted_at,
                null,
                $entry->id,
                null,
                true
            );

            return 'posted';
        });
    }

    private function evaluateEligibility(AccessPoint $accessPoint, ?BillingLedgerEntry $currentDebit, array $healthRuntime): array
    {
        $postBillingIssue = $currentDebit ? $this->postBillingIssue($accessPoint, $currentDebit) : null;

        if ($postBillingIssue) {
            return [
                'state' => AccessPoint::BILLING_STATE_BLOCKED,
                'reason' => $postBillingIssue,
            ];
        }

        if (! $this->hasValidCurrentOwnership($accessPoint)) {
            return [
                'state' => AccessPoint::BILLING_STATE_BLOCKED,
                'reason' => self::BILLING_BLOCK_INVALID_OWNERSHIP,
            ];
        }

        if (! $accessPoint->first_confirmed_connected_at) {
            return [
                'state' => AccessPoint::BILLING_STATE_UNBILLED,
                'reason' => null,
            ];
        }

        if ($healthRuntime['degraded']) {
            return [
                'state' => AccessPoint::BILLING_STATE_BLOCKED,
                'reason' => self::BILLING_BLOCK_HEALTH_AUTOMATION_DEGRADED,
            ];
        }

        if (! $this->healthService->present($accessPoint)['is_fresh']) {
            return [
                'state' => AccessPoint::BILLING_STATE_BLOCKED,
                'reason' => self::BILLING_BLOCK_STALE_HEALTH,
            ];
        }

        $ownershipAt = $this->trustedOwnershipAt($accessPoint);
        if ($ownershipAt
            && $accessPoint->first_confirmed_connected_at->lt($ownershipAt)
            && ! $this->hasManualEligibilityConfirmation($accessPoint, $ownershipAt)) {
            return [
                'state' => AccessPoint::BILLING_STATE_BLOCKED,
                'reason' => self::BILLING_BLOCK_CONFIRMED_CONNECTION_PREDATES_OWNERSHIP,
            ];
        }

        return [
            'state' => 'eligible',
            'reason' => null,
        ];
    }

    private function resolvePredatesOwnershipIncident(
        AccessPoint $accessPoint,
        User $admin,
        string $reason,
        ?string $notes,
        array $healthRuntime,
        ?BillingLedgerEntry $currentGenerationDebit,
        Carbon $resolvedAt,
    ): AccessPoint {
        if ($currentGenerationDebit) {
            throw new RuntimeException('This AP already has a posted connection fee for the current billing generation.');
        }

        if ($this->effectiveIncidentReason($accessPoint) !== self::BILLING_BLOCK_CONFIRMED_CONNECTION_PREDATES_OWNERSHIP) {
            throw new RuntimeException('This AP is not blocked by the trusted-ownership timing rule.');
        }

        $this->assertResolutionEvidenceIsFresh($accessPoint, $healthRuntime);

        $accessPoint->forceFill([
            'billing_eligibility_confirmed_at' => $resolvedAt,
            'billing_eligibility_confirmed_by_user_id' => $admin->id,
            'latest_billing_resolution_reason' => $reason,
            'billing_resolution_metadata' => array_merge($accessPoint->billing_resolution_metadata ?? [], [
                'last_resolution' => [
                    'action' => self::RESOLUTION_ACTION_CONFIRM_ELIGIBILITY,
                    'resolved_at' => $resolvedAt->toIso8601String(),
                    'resolved_by_user_id' => $admin->id,
                    'resolved_by_name' => $admin->name,
                    'reason' => $reason,
                    'notes' => $notes,
                ],
            ]),
        ])->save();

        $this->syncAccessPointBillingState(
            $accessPoint,
            AccessPoint::BILLING_STATE_UNBILLED,
            null,
            null,
            $accessPoint->latest_billing_entry_id,
            null,
            true,
            $resolvedAt
        );

        return $accessPoint->refresh();
    }

    private function resolveCorrectedOwnershipIncident(
        AccessPoint $accessPoint,
        User $admin,
        string $reason,
        ?string $notes,
        array $healthRuntime,
        ?BillingLedgerEntry $latestActiveDebit,
        Carbon $resolvedAt,
    ): AccessPoint {
        if ($this->effectiveIncidentReason($accessPoint) !== self::BILLING_BLOCK_OWNERSHIP_CORRECTED_AFTER_BILLING) {
            throw new RuntimeException('This AP is not waiting for corrected-ownership billing resolution.');
        }

        $this->assertResolutionEvidenceIsFresh($accessPoint, $healthRuntime);

        $latestEntryId = $accessPoint->latest_billing_entry_id;

        if ($latestActiveDebit) {
            $credit = $this->createReversalEntry($accessPoint, $latestActiveDebit, $admin, $reason, $notes);
            $latestEntryId = $credit->id;
        }

        $accessPoint->forceFill([
            'billing_charge_generation' => (int) $accessPoint->billing_charge_generation + 1,
            'billing_eligibility_confirmed_at' => $resolvedAt,
            'billing_eligibility_confirmed_by_user_id' => $admin->id,
            'latest_billing_resolution_reason' => $reason,
            'billing_resolution_metadata' => array_merge($accessPoint->billing_resolution_metadata ?? [], [
                'last_resolution' => [
                    'action' => self::RESOLUTION_ACTION_AUTHORIZE_REPOST,
                    'resolved_at' => $resolvedAt->toIso8601String(),
                    'resolved_by_user_id' => $admin->id,
                    'resolved_by_name' => $admin->name,
                    'reason' => $reason,
                    'notes' => $notes,
                    'authorized_billing_generation' => (int) $accessPoint->billing_charge_generation + 1,
                ],
            ]),
        ])->save();

        $this->syncAccessPointBillingState(
            $accessPoint,
            AccessPoint::BILLING_STATE_UNBILLED,
            null,
            null,
            $latestEntryId,
            null,
            true,
            $resolvedAt
        );

        return $accessPoint->refresh();
    }

    private function assertResolutionEvidenceIsFresh(AccessPoint $accessPoint, array $healthRuntime): void
    {
        if (! $this->hasValidCurrentOwnership($accessPoint)) {
            throw new RuntimeException('Billing resolution is blocked because AP ownership is not currently trustworthy.');
        }

        if (! $accessPoint->first_confirmed_connected_at) {
            throw new RuntimeException('Billing resolution is blocked because the AP still has no confirmed connected timestamp.');
        }

        if ($healthRuntime['degraded']) {
            throw new RuntimeException('Billing resolution is blocked because AP health automation is degraded.');
        }

        if (! $this->healthService->present($accessPoint)['is_fresh']) {
            throw new RuntimeException('Billing resolution is blocked because the AP health signal is stale.');
        }
    }

    private function createReversalEntry(
        AccessPoint $accessPoint,
        BillingLedgerEntry $originalDebit,
        User $admin,
        string $reason,
        ?string $notes,
    ): BillingLedgerEntry {
        if ($originalDebit->reversalEntry()->exists() || $originalDebit->state === BillingLedgerEntry::STATE_REVERSED) {
            throw new RuntimeException('This AP connection fee has already been reversed.');
        }

        $credit = BillingLedgerEntry::query()->create([
            'operator_id' => $originalDebit->operator_id,
            'site_id' => $originalDebit->site_id,
            'access_point_id' => $accessPoint->id,
            'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
            'direction' => BillingLedgerEntry::DIRECTION_CREDIT,
            'amount' => $originalDebit->amount,
            'currency' => $originalDebit->currency,
            'state' => BillingLedgerEntry::STATE_POSTED,
            'billable_key' => $this->reversalBillableKey($originalDebit),
            'triggered_at' => now(),
            'posted_at' => now(),
            'reversal_of_id' => $originalDebit->id,
            'source' => BillingLedgerEntry::SOURCE_ADMIN_REVERSAL,
            'metadata' => [
                'reason' => $reason,
                'notes' => $notes,
                'reversed_by_user_id' => $admin->id,
                'reversed_by_name' => $admin->name,
                'original_billable_key' => $originalDebit->billable_key,
            ],
        ]);

        $originalDebit->forceFill([
            'state' => BillingLedgerEntry::STATE_REVERSED,
            'voided_at' => now(),
            'metadata' => array_merge($originalDebit->metadata ?? [], [
                'reversal_posted_at' => now()->toIso8601String(),
                'reversal_entry_id' => $credit->id,
                'reversal_reason' => $reason,
            ]),
        ])->save();

        return $credit;
    }

    private function postBillingIssue(AccessPoint $accessPoint, BillingLedgerEntry $currentDebit): ?string
    {
        if ($accessPoint->ownership_corrected_at && $currentDebit->posted_at
            && $accessPoint->ownership_corrected_at->gte($currentDebit->posted_at)) {
            return self::BILLING_BLOCK_OWNERSHIP_CORRECTED_AFTER_BILLING;
        }

        if (! $this->hasValidCurrentOwnership($accessPoint)) {
            return self::BILLING_BLOCK_INVALID_OWNERSHIP;
        }

        return null;
    }

    private function effectiveIncidentReason(AccessPoint $accessPoint): ?string
    {
        if ($accessPoint->billing_incident_state) {
            return match ($accessPoint->billing_incident_state) {
                AccessPoint::BILLING_INCIDENT_WAITING_FOR_FRESH_HEALTH => self::BILLING_BLOCK_STALE_HEALTH,
                AccessPoint::BILLING_INCIDENT_INVALID_OWNERSHIP => self::BILLING_BLOCK_INVALID_OWNERSHIP,
                AccessPoint::BILLING_INCIDENT_PREDATES_TRUSTED_OWNERSHIP => self::BILLING_BLOCK_CONFIRMED_CONNECTION_PREDATES_OWNERSHIP,
                AccessPoint::BILLING_INCIDENT_CORRECTED_AFTER_BILLING => self::BILLING_BLOCK_OWNERSHIP_CORRECTED_AFTER_BILLING,
                AccessPoint::BILLING_INCIDENT_AUTOMATION_DEGRADED => self::BILLING_BLOCK_HEALTH_AUTOMATION_DEGRADED,
                default => $accessPoint->billing_block_reason,
            };
        }

        return $accessPoint->billing_block_reason;
    }

    private function currentGenerationDebitForUpdate(AccessPoint $accessPoint): ?BillingLedgerEntry
    {
        return BillingLedgerEntry::query()
            ->where('access_point_id', $accessPoint->id)
            ->where('entry_type', BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE)
            ->where('direction', BillingLedgerEntry::DIRECTION_DEBIT)
            ->where('billable_key', $this->connectionFeeBillableKey($accessPoint))
            ->lockForUpdate()
            ->first();
    }

    private function latestActiveDebitForUpdate(int $accessPointId): ?BillingLedgerEntry
    {
        return BillingLedgerEntry::query()
            ->where('access_point_id', $accessPointId)
            ->where('entry_type', BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE)
            ->where('direction', BillingLedgerEntry::DIRECTION_DEBIT)
            ->where('state', BillingLedgerEntry::STATE_POSTED)
            ->whereDoesntHave('reversalEntry')
            ->lockForUpdate()
            ->latest('posted_at')
            ->first();
    }

    private function hasValidCurrentOwnership(AccessPoint $accessPoint): bool
    {
        if (! $accessPoint->claimed_by_operator_id || ! $accessPoint->site_id) {
            return false;
        }

        if ($accessPoint->adoption_state !== AccessPoint::ADOPTION_STATE_ADOPTED) {
            return false;
        }

        /** @var Site|null $site */
        $site = $accessPoint->site;
        /** @var Operator|null $operator */
        $operator = $site?->operator;

        if (! $site || ! $operator || (int) $site->operator_id !== (int) $accessPoint->claimed_by_operator_id) {
            return false;
        }

        if (! $operator->isApproved()) {
            return false;
        }

        return filled($accessPoint->approved_claim_id) || filled($accessPoint->ownership_corrected_at);
    }

    private function trustedOwnershipAt(AccessPoint $accessPoint): ?Carbon
    {
        return $accessPoint->ownership_corrected_at
            ?? $accessPoint->ownership_verified_at
            ?? $accessPoint->claimed_at;
    }

    private function billingTriggeredAt(AccessPoint $accessPoint): ?Carbon
    {
        $ownershipAt = $this->trustedOwnershipAt($accessPoint);

        if ($ownershipAt
            && $accessPoint->first_confirmed_connected_at
            && $accessPoint->first_confirmed_connected_at->lt($ownershipAt)
            && $accessPoint->billing_eligibility_confirmed_at) {
            return $accessPoint->billing_eligibility_confirmed_at;
        }

        return $accessPoint->first_confirmed_connected_at;
    }

    private function hasManualEligibilityConfirmation(AccessPoint $accessPoint, Carbon $ownershipAt): bool
    {
        return $accessPoint->billing_eligibility_confirmed_at
            && $accessPoint->billing_eligibility_confirmed_by_user_id
            && $accessPoint->billing_eligibility_confirmed_at->gte($ownershipAt);
    }

    private function syncAccessPointBillingState(
        AccessPoint $accessPoint,
        string $state,
        ?Carbon $postedAt,
        ?string $blockReason,
        ?int $latestEntryId,
        ?string $incidentState = null,
        bool $clearIncident = false,
        ?Carbon $resolvedAt = null,
    ): void {
        $payload = [
            'billing_state' => $state,
            'billing_posted_at' => $postedAt,
            'billing_block_reason' => $blockReason,
            'latest_billing_entry_id' => $latestEntryId,
        ];

        if ($clearIncident) {
            $payload['billing_incident_state'] = null;
            $payload['billing_incident_resolved_at'] = $resolvedAt ?? now();
        } elseif ($incidentState) {
            $payload['billing_incident_state'] = $incidentState;
        }

        $accessPoint->forceFill($payload)->save();
    }

    private function openBillingIncident(
        AccessPoint $accessPoint,
        string $blockReason,
        ?Carbon $postedAt,
        ?int $latestEntryId,
    ): void {
        $now = now();
        $incidentState = $this->incidentStateForBlockReason($blockReason);
        $openedAt = $accessPoint->billing_incident_state === $incidentState
            && $accessPoint->billing_incident_opened_at
            && ! $accessPoint->billing_incident_resolved_at
                ? $accessPoint->billing_incident_opened_at
                : $now;

        $this->syncAccessPointBillingState(
            $accessPoint,
            AccessPoint::BILLING_STATE_BLOCKED,
            $postedAt,
            $blockReason,
            $latestEntryId,
            $incidentState,
            false
        );

        $accessPoint->forceFill([
            'billing_incident_opened_at' => $openedAt,
            'billing_incident_resolved_at' => null,
            'latest_billing_resolution_reason' => $accessPoint->latest_billing_resolution_reason,
            'billing_resolution_metadata' => array_merge($accessPoint->billing_resolution_metadata ?? [], [
                'last_incident' => [
                    'state' => $incidentState,
                    'opened_at' => $openedAt->toIso8601String(),
                    'reason' => $blockReason,
                ],
            ]),
        ])->save();
    }

    private function incidentStateForBlockReason(string $blockReason): string
    {
        return match ($blockReason) {
            self::BILLING_BLOCK_HEALTH_AUTOMATION_DEGRADED => AccessPoint::BILLING_INCIDENT_AUTOMATION_DEGRADED,
            self::BILLING_BLOCK_STALE_HEALTH => AccessPoint::BILLING_INCIDENT_WAITING_FOR_FRESH_HEALTH,
            self::BILLING_BLOCK_INVALID_OWNERSHIP => AccessPoint::BILLING_INCIDENT_INVALID_OWNERSHIP,
            self::BILLING_BLOCK_CONFIRMED_CONNECTION_PREDATES_OWNERSHIP => AccessPoint::BILLING_INCIDENT_PREDATES_TRUSTED_OWNERSHIP,
            self::BILLING_BLOCK_OWNERSHIP_CORRECTED_AFTER_BILLING => AccessPoint::BILLING_INCIDENT_CORRECTED_AFTER_BILLING,
            default => AccessPoint::BILLING_INCIDENT_MANUAL_REVIEW_REQUIRED,
        };
    }

    private function availableResolutionActions(AccessPoint $accessPoint): array
    {
        return match ($accessPoint->billing_incident_state) {
            AccessPoint::BILLING_INCIDENT_PREDATES_TRUSTED_OWNERSHIP => [self::RESOLUTION_ACTION_CONFIRM_ELIGIBILITY],
            AccessPoint::BILLING_INCIDENT_CORRECTED_AFTER_BILLING => [self::RESOLUTION_ACTION_AUTHORIZE_REPOST],
            default => [],
        };
    }

    private function hasPostedDebit(int $accessPointId): bool
    {
        return BillingLedgerEntry::query()
            ->where('access_point_id', $accessPointId)
            ->where('entry_type', BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE)
            ->where('direction', BillingLedgerEntry::DIRECTION_DEBIT)
            ->exists();
    }

    private function notePostHeartbeat(?Carbon $at = null): void
    {
        Cache::put(self::POST_HEARTBEAT_CACHE_KEY, ($at ?? now())->toIso8601String(), now()->addDay());
    }

    private function parseHeartbeat(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function connectionFeeBillableKey(AccessPoint $accessPoint): string
    {
        if ((int) $accessPoint->billing_charge_generation <= 0) {
            return "ap-connection-fee:{$accessPoint->id}";
        }

        return "ap-connection-fee:{$accessPoint->id}:rebill:{$accessPoint->billing_charge_generation}";
    }

    private function reversalBillableKey(BillingLedgerEntry $entry): string
    {
        return "ap-connection-fee-reversal:{$entry->id}";
    }

    private function connectionFeeAmount(): float
    {
        return (float) config('omada.billing_connection_fee_amount', 500);
    }

    private function connectionFeeCurrency(): string
    {
        return (string) config('omada.billing_connection_fee_currency', 'PHP');
    }

    private function billingRuntimeDegradedAfterSeconds(): int
    {
        return max(60, (int) config('omada.billing_runtime_degraded_after_seconds', 300));
    }

    private function billingLabel(?string $state): string
    {
        return match ($state) {
            AccessPoint::BILLING_STATE_BILLED => 'Billed',
            AccessPoint::BILLING_STATE_PENDING_POST => 'Posting',
            AccessPoint::BILLING_STATE_BLOCKED => 'Blocked',
            AccessPoint::BILLING_STATE_REVERSED => 'Reversed',
            default => 'Unbilled',
        };
    }

    private function billingIncidentLabel(?string $state): ?string
    {
        return match ($state) {
            AccessPoint::BILLING_INCIDENT_WAITING_FOR_FRESH_HEALTH => 'Waiting for fresh health',
            AccessPoint::BILLING_INCIDENT_INVALID_OWNERSHIP => 'Invalid ownership',
            AccessPoint::BILLING_INCIDENT_PREDATES_TRUSTED_OWNERSHIP => 'Confirmed connection predates trusted ownership',
            AccessPoint::BILLING_INCIDENT_CORRECTED_AFTER_BILLING => 'Ownership corrected after billing',
            AccessPoint::BILLING_INCIDENT_AUTOMATION_DEGRADED => 'Automation degraded',
            AccessPoint::BILLING_INCIDENT_MANUAL_REVIEW_REQUIRED => 'Manual review required',
            default => null,
        };
    }
}
