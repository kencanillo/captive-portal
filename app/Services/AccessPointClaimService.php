<?php

namespace App\Services;

use App\Models\AccessPoint;
use App\Models\AccessPointClaim;
use App\Models\AccessPointOwnershipCorrection;
use App\Models\BillingLedgerEntry;
use App\Models\ControllerSetting;
use App\Models\Operator;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AccessPointClaimService
{
    public function __construct(
        private readonly OmadaService $omadaService,
        private readonly AccessPointBillingService $billingService,
    ) {
    }

    public function inventoryHealth(): array
    {
        $latestSync = $this->latestInventorySyncedAt();

        return [
            'latest_synced_at' => $latestSync?->toDateTimeString(),
            'is_fresh' => $this->isFreshTimestamp($latestSync),
            'max_age_seconds' => $this->claimSyncFreshnessSeconds(),
        ];
    }

    public function submit(Operator $operator, array $attributes): AccessPointClaim
    {
        $site = Site::query()
            ->whereKey($attributes['site_id'])
            ->where('operator_id', $operator->id)
            ->first();

        if (! $site) {
            throw ValidationException::withMessages([
                'site_id' => 'Pick one of your assigned sites.',
            ]);
        }

        $normalizedSerial = $this->normalizeSerial($attributes['requested_serial_number'] ?? null);
        $normalizedMac = $this->normalizeMac($attributes['requested_mac_address'] ?? null);

        if (! $normalizedSerial && ! $normalizedMac) {
            throw ValidationException::withMessages([
                'requested_serial_number' => 'Provide a serial number or MAC address. AP name is only a hint.',
                'requested_mac_address' => 'Provide a serial number or MAC address. AP name is only a hint.',
            ]);
        }

        return DB::transaction(function () use ($operator, $site, $attributes, $normalizedSerial, $normalizedMac): AccessPointClaim {
            $existingClaims = $this->openClaimsForFingerprint($normalizedSerial, $normalizedMac, true);

            $duplicate = $existingClaims->first(function (AccessPointClaim $claim) use ($operator, $site): bool {
                return $claim->operator_id === $operator->id && $claim->site_id === $site->id;
            });

            if ($duplicate) {
                return $duplicate;
            }

            if ($existingClaims->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'requested_mac_address' => 'This device fingerprint already has an active claim. Resolve that claim before creating another one.',
                ]);
            }

            return AccessPointClaim::query()->create([
                'operator_id' => $operator->id,
                'site_id' => $site->id,
                'requested_serial_number' => $this->stringOrNull($attributes['requested_serial_number'] ?? null),
                'requested_serial_number_normalized' => $normalizedSerial,
                'requested_mac_address' => $normalizedMac,
                'requested_mac_address_normalized' => $normalizedMac,
                'requested_ap_name' => $this->stringOrNull($attributes['requested_ap_name'] ?? null),
                'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
                'claim_match_status' => AccessPointClaim::MATCH_STATUS_UNMATCHED,
                'claimed_at' => now(),
            ]);
        });
    }

    public function approve(AccessPointClaim $claim, User $admin, ?string $reviewNotes = null): AccessPointClaim
    {
        $settings = ControllerSetting::singleton();
        $this->assertControllerSyncAvailable($settings);

        $this->omadaService->syncAccessPoints($settings);
        $checkedAt = now();

        if (! $this->isFreshTimestamp($this->latestInventorySyncedAt())) {
            AccessPointClaim::query()->whereKey($claim->id)->update([
                'claim_match_status' => AccessPointClaim::MATCH_STATUS_STALE_SYNC,
                'sync_freshness_checked_at' => $checkedAt,
                'failure_reason' => 'Omada inventory is stale. Run a fresh sync before approving claims.',
            ]);

            throw new RuntimeException('Omada inventory is stale. Run a fresh sync before approving claims.');
        }

        $result = DB::transaction(function () use ($claim, $admin, $reviewNotes, $checkedAt) {
            /** @var AccessPointClaim $lockedClaim */
            $lockedClaim = AccessPointClaim::query()
                ->with(['site', 'operator'])
                ->lockForUpdate()
                ->findOrFail($claim->id);

            if (! in_array($lockedClaim->claim_status, [
                AccessPointClaim::STATUS_PENDING_REVIEW,
                AccessPointClaim::STATUS_SUBMITTED,
            ], true)) {
                throw new RuntimeException('This claim is no longer waiting for review.');
            }

            $match = $this->resolveUniquePendingMatch($lockedClaim);

            if (! $this->isFreshTimestamp($match->last_synced_at)) {
                $lockedClaim->forceFill([
                    'claim_match_status' => AccessPointClaim::MATCH_STATUS_STALE_SYNC,
                    'sync_freshness_checked_at' => $checkedAt,
                    'failure_reason' => 'The matched AP inventory is stale. Re-sync before approving claims.',
                ])->save();

                return [
                    'outcome' => 'stale_sync',
                    'message' => 'The matched AP inventory is stale. Re-sync before approving claims.',
                ];
            }

            $conflictingClaims = $this->findConflictingClaimsForAccessPoint($lockedClaim, $match);

            if ($conflictingClaims->isNotEmpty()) {
                $this->escalateClaimConflict(
                    $conflictingClaims->prepend($lockedClaim),
                    $match,
                    $checkedAt,
                    'This pending AP now matches multiple open claims. Manual review is required.'
                );

                return [
                    'outcome' => 'conflict',
                    'message' => 'This pending AP now matches multiple open claims. Manual review is required.',
                ];
            }

            $lockedClaim->forceFill([
                'claim_status' => AccessPointClaim::STATUS_APPROVED,
                'claim_match_status' => AccessPointClaim::MATCH_STATUS_RESERVED,
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $admin->id,
                'review_notes' => $reviewNotes,
                'denial_reason' => null,
                'failure_reason' => null,
                'matched_access_point_id' => $match->id,
                'matched_omada_device_id' => $match->omada_device_id,
                'match_snapshot' => $this->buildMatchSnapshot($lockedClaim, $match, $checkedAt),
                'matched_at' => $checkedAt,
                'requires_re_review' => false,
                'conflict_state' => null,
                'sync_freshness_checked_at' => $checkedAt,
            ])->save();

            return $lockedClaim->fresh([
                'operator.user',
                'site',
                'reviewedBy',
                'matchedAccessPoint.site',
            ]);
        });

        if (is_array($result)) {
            throw new RuntimeException($result['message']);
        }

        return $result;
    }

    public function deny(AccessPointClaim $claim, User $admin, string $denialReason, ?string $reviewNotes = null): AccessPointClaim
    {
        return DB::transaction(function () use ($claim, $admin, $denialReason, $reviewNotes): AccessPointClaim {
            /** @var AccessPointClaim $lockedClaim */
            $lockedClaim = AccessPointClaim::query()->lockForUpdate()->findOrFail($claim->id);

            if (! in_array($lockedClaim->claim_status, [
                AccessPointClaim::STATUS_PENDING_REVIEW,
                AccessPointClaim::STATUS_SUBMITTED,
                AccessPointClaim::STATUS_APPROVED,
            ], true)) {
                throw new RuntimeException('This claim can no longer be denied.');
            }

            $lockedClaim->forceFill([
                'claim_status' => AccessPointClaim::STATUS_DENIED,
                'reviewed_at' => now(),
                'reviewed_by_user_id' => $admin->id,
                'review_notes' => $reviewNotes,
                'denial_reason' => $denialReason,
            ])->save();

            return $lockedClaim;
        });
    }

    public function adopt(AccessPointClaim $claim, Operator $operator): AccessPointClaim
    {
        $settings = ControllerSetting::singleton();
        $this->assertControllerSyncAvailable($settings);

        $this->omadaService->syncAccessPoints($settings);
        $checkedAt = now();

        if (! $this->isFreshTimestamp($this->latestInventorySyncedAt())) {
            $this->markClaimRequiresRereview(
                $claim,
                'Omada inventory is stale. Run a fresh sync and re-review the claim before adoption.',
                AccessPointClaim::MATCH_STATUS_STALE_SYNC,
                $checkedAt
            );

            throw new RuntimeException('Omada inventory is stale. Run a fresh sync and re-review the claim before adoption.');
        }

        $result = DB::transaction(function () use ($claim, $operator, $checkedAt) {
            /** @var AccessPointClaim $claimRow */
            $claimRow = AccessPointClaim::query()
                ->with(['matchedAccessPoint', 'site'])
                ->lockForUpdate()
                ->findOrFail($claim->id);

            if ($claimRow->operator_id !== $operator->id) {
                throw new RuntimeException('You cannot adopt another operator\'s claimed device.');
            }

            if ($claimRow->claim_status === AccessPointClaim::STATUS_ADOPTED) {
                return $claimRow;
            }

            if (! in_array($claimRow->claim_status, [
                AccessPointClaim::STATUS_APPROVED,
                AccessPointClaim::STATUS_ADOPTION_FAILED,
            ], true)) {
                throw new RuntimeException('This claim is not approved for adoption.');
            }

            if ($claimRow->requires_re_review || ! $claimRow->match_snapshot || $claimRow->claim_match_status !== AccessPointClaim::MATCH_STATUS_RESERVED) {
                $this->markClaimRequiresRereview(
                    $claimRow,
                    'This claim no longer has a trusted reservation. Admin re-review is required before adoption.',
                    AccessPointClaim::MATCH_STATUS_STALE_MATCH,
                    $checkedAt
                );

                return [
                    'outcome' => 'stale_match',
                    'message' => 'This claim no longer has a trusted reservation. Admin re-review is required before adoption.',
                ];
            }

            try {
                $match = $this->resolveUniquePendingMatch($claimRow);
            } catch (RuntimeException $exception) {
                $this->markClaimRequiresRereview(
                    $claimRow,
                    'The approved claim no longer matches a live pending device. Admin re-review is required before adoption.',
                    AccessPointClaim::MATCH_STATUS_STALE_MATCH,
                    $checkedAt
                );

                return [
                    'outcome' => 'stale_match',
                    'message' => 'The approved claim no longer matches a live pending device. Admin re-review is required before adoption.',
                ];
            }

            if (! $this->isFreshTimestamp($match->last_synced_at)) {
                $this->markClaimRequiresRereview(
                    $claimRow,
                    'The matched AP inventory is stale. Admin re-review is required before adoption.',
                    AccessPointClaim::MATCH_STATUS_STALE_SYNC,
                    $checkedAt
                );

                return [
                    'outcome' => 'stale_sync',
                    'message' => 'The matched AP inventory is stale. Admin re-review is required before adoption.',
                ];
            }

            $conflictingClaims = $this->findConflictingClaimsForAccessPoint($claimRow, $match);

            if ($conflictingClaims->isNotEmpty()) {
                $this->escalateClaimConflict(
                    $conflictingClaims->prepend($claimRow),
                    $match,
                    $checkedAt,
                    'This pending AP now matches multiple open claims. Manual review is required before adoption.'
                );

                return [
                    'outcome' => 'conflict',
                    'message' => 'This pending AP now matches multiple open claims. Manual review is required before adoption.',
                ];
            }

            if (! $this->matchesReservationSnapshot($claimRow, $match)) {
                $this->markClaimRequiresRereview(
                    $claimRow,
                    'The pending Omada device drifted after approval. Admin re-review is required before adoption.',
                    AccessPointClaim::MATCH_STATUS_STALE_MATCH,
                    $checkedAt
                );

                return [
                    'outcome' => 'stale_match',
                    'message' => 'The pending Omada device drifted after approval. Admin re-review is required before adoption.',
                ];
            }

            $claimRow->forceFill([
                'claim_status' => AccessPointClaim::STATUS_ADOPTION_PENDING,
                'matched_access_point_id' => $match->id,
                'matched_omada_device_id' => $match->omada_device_id,
                'match_snapshot' => $this->buildMatchSnapshot($claimRow, $match, $checkedAt),
                'matched_at' => $checkedAt,
                'adoption_attempted_at' => now(),
                'failure_reason' => null,
                'sync_freshness_checked_at' => $checkedAt,
            ])->save();

            return $claimRow;
        });

        if (is_array($result)) {
            throw new RuntimeException($result['message']);
        }

        if ($result->claim_status === AccessPointClaim::STATUS_ADOPTED) {
            return $result;
        }

        $matchedAccessPoint = AccessPoint::query()->find($result->matched_access_point_id);

        if (! $matchedAccessPoint) {
            return $this->markAdoptionFailed(
                $result,
                null,
                'The matched pending device disappeared before adoption could start.',
                [
                    'outcome' => 'missing_pending_device',
                    'retryable' => false,
                ],
                AccessPointClaim::MATCH_STATUS_STALE_MATCH,
                true
            );
        }

        try {
            $response = $this->omadaService->adoptDevice($settings, $matchedAccessPoint->mac_address);
        } catch (Throwable $exception) {
            return $this->markAdoptionFailed(
                $result,
                $matchedAccessPoint,
                $exception->getMessage(),
                [
                    'outcome' => 'controller_error',
                    'retryable' => true,
                    'error' => $exception->getMessage(),
                ],
                AccessPointClaim::MATCH_STATUS_RESERVED,
                false
            );
        }

        $completionCheckedAt = now();

        $completionResult = DB::transaction(function () use ($result, $matchedAccessPoint, $response, $completionCheckedAt) {
            /** @var AccessPointClaim $claimRow */
            $claimRow = AccessPointClaim::query()->lockForUpdate()->findOrFail($result->id);
            /** @var AccessPoint $ap */
            $ap = AccessPoint::query()->lockForUpdate()->findOrFail($matchedAccessPoint->id);

            if ($claimRow->claim_status === AccessPointClaim::STATUS_ADOPTED) {
                return $claimRow;
            }

            if ($claimRow->claim_status !== AccessPointClaim::STATUS_ADOPTION_PENDING) {
                return [
                    'outcome' => 'adoption_state_invalid',
                    'message' => 'This claim is no longer in an adoptable state.',
                ];
            }

            try {
                $freshMatch = $this->resolveUniquePendingMatch($claimRow);
            } catch (RuntimeException $exception) {
                $this->markClaimRequiresRereview(
                    $claimRow,
                    'The approved claim no longer matches a live pending device. Admin re-review is required before finalizing adoption.',
                    AccessPointClaim::MATCH_STATUS_STALE_MATCH,
                    $completionCheckedAt
                );

                return [
                    'outcome' => 'stale_match',
                    'message' => 'The approved claim no longer matches a live pending device. Admin re-review is required before finalizing adoption.',
                ];
            }

            if (! $this->isFreshTimestamp($freshMatch->last_synced_at)) {
                $this->markClaimRequiresRereview(
                    $claimRow,
                    'The matched AP inventory is stale after adoption attempt. Admin re-review is required.',
                    AccessPointClaim::MATCH_STATUS_STALE_SYNC,
                    $completionCheckedAt
                );

                return [
                    'outcome' => 'stale_sync',
                    'message' => 'The matched AP inventory is stale after adoption attempt. Admin re-review is required.',
                ];
            }

            $conflictingClaims = $this->findConflictingClaimsForAccessPoint($claimRow, $freshMatch);

            if ($conflictingClaims->isNotEmpty()) {
                $this->escalateClaimConflict(
                    $conflictingClaims->prepend($claimRow),
                    $freshMatch,
                    $completionCheckedAt,
                    'This pending AP now matches multiple open claims. Manual review is required before finalizing adoption.'
                );

                return [
                    'outcome' => 'conflict',
                    'message' => 'This pending AP now matches multiple open claims. Manual review is required before finalizing adoption.',
                ];
            }

            if ($freshMatch->id !== $ap->id || ! $this->matchesReservationSnapshot($claimRow, $freshMatch)) {
                $this->markClaimRequiresRereview(
                    $claimRow,
                    'The pending Omada device changed before adoption completed. Admin re-review is required.',
                    AccessPointClaim::MATCH_STATUS_STALE_MATCH,
                    $completionCheckedAt
                );

                return [
                    'outcome' => 'stale_match',
                    'message' => 'The pending Omada device changed before adoption completed. Admin re-review is required.',
                ];
            }

            $ap->forceFill([
                'site_id' => $claimRow->site_id,
                'claimed_by_operator_id' => $claimRow->operator_id,
                'approved_claim_id' => $claimRow->id,
                'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
                'adoption_state' => AccessPoint::ADOPTION_STATE_ADOPTED,
                'claimed_at' => $ap->claimed_at ?? now(),
                'ownership_verified_at' => now(),
                'ownership_verified_by_user_id' => $claimRow->reviewed_by_user_id,
            ]);

            $ap->save();

            $claimRow->forceFill([
                'claim_status' => AccessPointClaim::STATUS_ADOPTED,
                'claim_match_status' => AccessPointClaim::MATCH_STATUS_RESERVED,
                'failure_reason' => null,
                'requires_re_review' => false,
                'conflict_state' => null,
                'sync_freshness_checked_at' => $completionCheckedAt,
                'adoption_result_metadata' => [
                    'outcome' => 'adopted',
                    'controller_response' => $response,
                    'adopted_access_point_id' => $ap->id,
                ],
            ])->save();

            return $claimRow->fresh([
                'operator.user',
                'site',
                'reviewedBy',
                'matchedAccessPoint.site',
            ]);
        });

        if (is_array($completionResult)) {
            return $this->markAdoptionFailed(
                $result,
                $matchedAccessPoint,
                $completionResult['message'],
                [
                    'outcome' => 'post_adoption_validation_failed',
                    'retryable' => false,
                    'controller_response' => $response,
                    'error' => $completionResult['message'],
                ],
                AccessPointClaim::MATCH_STATUS_STALE_MATCH,
                true
            );
        }

        return $completionResult;
    }

    public function correctOwnership(AccessPoint $accessPoint, User $admin, array $attributes): AccessPoint
    {
        return DB::transaction(function () use ($accessPoint, $admin, $attributes): AccessPoint {
            /** @var AccessPoint $lockedAccessPoint */
            $lockedAccessPoint = AccessPoint::query()->lockForUpdate()->findOrFail($accessPoint->id);

            if (! $lockedAccessPoint->claimed_by_operator_id || $lockedAccessPoint->adoption_state !== AccessPoint::ADOPTION_STATE_ADOPTED) {
                throw new RuntimeException('Only adopted, owned APs can be corrected.');
            }

            $targetOperator = Operator::query()->lockForUpdate()->findOrFail($attributes['operator_id']);
            $targetSite = Site::query()
                ->lockForUpdate()
                ->whereKey($attributes['site_id'])
                ->where('operator_id', $targetOperator->id)
                ->first();

            if (! $targetSite) {
                throw ValidationException::withMessages([
                    'site_id' => 'The selected site does not belong to the target operator.',
                ]);
            }

            if ((int) $lockedAccessPoint->claimed_by_operator_id === (int) $targetOperator->id
                && (int) $lockedAccessPoint->site_id === (int) $targetSite->id) {
                throw new RuntimeException('This AP is already assigned to that operator and site.');
            }

            AccessPointOwnershipCorrection::query()->create([
                'access_point_id' => $lockedAccessPoint->id,
                'from_operator_id' => $lockedAccessPoint->claimed_by_operator_id,
                'to_operator_id' => $targetOperator->id,
                'from_site_id' => $lockedAccessPoint->site_id,
                'to_site_id' => $targetSite->id,
                'from_approved_claim_id' => $lockedAccessPoint->approved_claim_id,
                'corrected_by_user_id' => $admin->id,
                'correction_reason' => $attributes['correction_reason'],
                'notes' => $attributes['notes'] ?? null,
                'metadata' => [
                    'previous_owner' => $lockedAccessPoint->claimedByOperator?->business_name,
                    'previous_site' => $lockedAccessPoint->site?->name,
                    'new_owner' => $targetOperator->business_name,
                    'new_site' => $targetSite->name,
                ],
                'corrected_at' => now(),
            ]);

            $lockedAccessPoint->forceFill([
                'claimed_by_operator_id' => $targetOperator->id,
                'site_id' => $targetSite->id,
                'approved_claim_id' => null,
                'ownership_verified_at' => now(),
                'ownership_verified_by_user_id' => $admin->id,
                'ownership_corrected_at' => now(),
                'ownership_corrected_by_user_id' => $admin->id,
                'latest_correction_reason' => $attributes['correction_reason'],
            ])->save();

            if (BillingLedgerEntry::query()
                ->where('access_point_id', $lockedAccessPoint->id)
                ->where('entry_type', BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE)
                ->where('direction', BillingLedgerEntry::DIRECTION_DEBIT)
                ->exists()) {
                $this->billingService->markOwnershipCorrectionRequiresManualResolution($lockedAccessPoint);
            }

            return $lockedAccessPoint->fresh([
                'site',
                'claimedByOperator',
                'approvedClaim',
                'ownershipCorrectedBy',
            ]);
        });
    }

    private function resolveUniquePendingMatch(AccessPointClaim $claim): AccessPoint
    {
        $candidates = AccessPoint::query()
            ->where('claim_status', AccessPoint::CLAIM_STATUS_PENDING)
            ->whereNull('claimed_by_operator_id')
            ->whereNull('approved_claim_id')
            ->get()
            ->filter(fn (AccessPoint $accessPoint) => $this->claimMatchesAccessPoint($claim, $accessPoint))
            ->values();

        if ($candidates->isEmpty()) {
            throw new RuntimeException('No pending Omada device currently matches the approved claim. Sync the controller and verify the site, serial, and MAC before approval or adoption.');
        }

        if ($candidates->count() > 1) {
            throw new RuntimeException('Multiple pending Omada devices match this claim. Resolve the conflict manually before continuing.');
        }

        return $candidates->first();
    }

    private function claimMatchesAccessPoint(AccessPointClaim $claim, AccessPoint $accessPoint): bool
    {
        if (! $accessPoint->site_id || $accessPoint->site_id !== $claim->site_id) {
            return false;
        }

        $serialMatches = true;
        if ($claim->requested_serial_number_normalized) {
            $serialMatches = $this->normalizeSerial($accessPoint->serial_number) === $claim->requested_serial_number_normalized;
        }

        $macMatches = true;
        if ($claim->requested_mac_address_normalized) {
            $macMatches = $this->normalizeMac($accessPoint->mac_address) === $claim->requested_mac_address_normalized;
        }

        if ($claim->requested_serial_number_normalized && $claim->requested_mac_address_normalized) {
            return $serialMatches && $macMatches;
        }

        if ($claim->requested_serial_number_normalized) {
            return $serialMatches;
        }

        return $macMatches;
    }

    private function findConflictingClaimsForAccessPoint(AccessPointClaim $claim, AccessPoint $accessPoint): Collection
    {
        return AccessPointClaim::query()
            ->lockForUpdate()
            ->whereKeyNot($claim->id)
            ->whereIn('claim_status', AccessPointClaim::openStatuses())
            ->where('site_id', $claim->site_id)
            ->get()
            ->filter(fn (AccessPointClaim $otherClaim) => $this->claimMatchesAccessPoint($otherClaim, $accessPoint))
            ->values();
    }

    private function buildMatchSnapshot(AccessPointClaim $claim, AccessPoint $accessPoint, Carbon $checkedAt): array
    {
        return [
            'matched_access_point_id' => $accessPoint->id,
            'matched_omada_device_id' => $accessPoint->omada_device_id,
            'site_id' => $accessPoint->site_id,
            'site_name' => $accessPoint->site?->name,
            'serial_number_normalized' => $this->normalizeSerial($accessPoint->serial_number),
            'mac_address_normalized' => $this->normalizeMac($accessPoint->mac_address),
            'last_synced_at' => $accessPoint->last_synced_at?->toDateTimeString(),
            'matched_at' => $checkedAt->toDateTimeString(),
            'requested_serial_number_normalized' => $claim->requested_serial_number_normalized,
            'requested_mac_address_normalized' => $claim->requested_mac_address_normalized,
            'match_confidence' => $claim->requested_serial_number_normalized && $claim->requested_mac_address_normalized
                ? 'serial_and_mac'
                : ($claim->requested_serial_number_normalized ? 'serial_only' : 'mac_only'),
        ];
    }

    private function matchesReservationSnapshot(AccessPointClaim $claim, AccessPoint $accessPoint): bool
    {
        $snapshot = $claim->match_snapshot ?? [];

        if (! is_array($snapshot) || $snapshot === []) {
            return false;
        }

        return (int) ($snapshot['matched_access_point_id'] ?? 0) === (int) $accessPoint->id
            && ($snapshot['matched_omada_device_id'] ?? null) === $accessPoint->omada_device_id
            && (int) ($snapshot['site_id'] ?? 0) === (int) $accessPoint->site_id
            && ($snapshot['serial_number_normalized'] ?? null) === $this->normalizeSerial($accessPoint->serial_number)
            && ($snapshot['mac_address_normalized'] ?? null) === $this->normalizeMac($accessPoint->mac_address);
    }

    private function escalateClaimConflict(Collection $claims, AccessPoint $accessPoint, Carbon $checkedAt, string $message): void
    {
        $claims->unique('id')->each(function (AccessPointClaim $claim) use ($accessPoint, $checkedAt, $message): void {
            $claim->forceFill([
                'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
                'claim_match_status' => AccessPointClaim::MATCH_STATUS_CONFLICT,
                'matched_access_point_id' => $accessPoint->id,
                'matched_omada_device_id' => $accessPoint->omada_device_id,
                'match_snapshot' => $this->buildMatchSnapshot($claim, $accessPoint, $checkedAt),
                'matched_at' => $checkedAt,
                'requires_re_review' => true,
                'conflict_state' => AccessPointClaim::CONFLICT_STATE_SPLIT_FINGERPRINT,
                'sync_freshness_checked_at' => $checkedAt,
                'failure_reason' => $message,
            ])->save();
        });
    }

    private function markClaimRequiresRereview(
        AccessPointClaim $claim,
        string $message,
        string $matchStatus,
        Carbon $checkedAt,
        ?string $conflictState = null,
    ): void {
        $claim->forceFill([
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
            'claim_match_status' => $matchStatus,
            'requires_re_review' => true,
            'conflict_state' => $conflictState,
            'sync_freshness_checked_at' => $checkedAt,
            'failure_reason' => $message,
        ])->save();
    }

    private function markAdoptionFailed(
        AccessPointClaim $claim,
        ?AccessPoint $matchedAccessPoint,
        string $failureReason,
        array $metadata,
        string $matchStatus,
        bool $requiresReReview,
    ): AccessPointClaim {
        return DB::transaction(function () use ($claim, $matchedAccessPoint, $failureReason, $metadata, $matchStatus, $requiresReReview): AccessPointClaim {
            /** @var AccessPointClaim $claimRow */
            $claimRow = AccessPointClaim::query()->lockForUpdate()->findOrFail($claim->id);

            $claimRow->forceFill([
                'claim_status' => AccessPointClaim::STATUS_ADOPTION_FAILED,
                'claim_match_status' => $matchStatus,
                'requires_re_review' => $requiresReReview,
                'failure_reason' => $failureReason,
                'adoption_result_metadata' => $metadata,
            ])->save();

            if ($matchedAccessPoint) {
                $ap = AccessPoint::query()->lockForUpdate()->find($matchedAccessPoint->id);

                if ($ap && ! $ap->claimed_by_operator_id) {
                    $ap->forceFill([
                        'adoption_state' => AccessPoint::ADOPTION_STATE_ADOPTION_FAILED,
                    ])->save();
                }
            }

            return $claimRow;
        });
    }

    private function assertControllerSyncAvailable(ControllerSetting $settings): void
    {
        if (! $settings->exists || ! $settings->canSyncAccessPoints()) {
            throw new RuntimeException('Controller sync credentials are required before AP ownership actions can run.');
        }
    }

    private function latestInventorySyncedAt(): ?Carbon
    {
        $latestSync = AccessPoint::query()->max('last_synced_at');

        return $latestSync ? Carbon::parse($latestSync) : null;
    }

    private function claimSyncFreshnessSeconds(): int
    {
        return max(60, (int) config('omada.claim_sync_max_age_seconds', 300));
    }

    private function isFreshTimestamp(mixed $timestamp): bool
    {
        if (! $timestamp) {
            return false;
        }

        $value = $timestamp instanceof Carbon ? $timestamp : Carbon::parse($timestamp);

        return $value->gte(now()->subSeconds($this->claimSyncFreshnessSeconds()));
    }

    private function openClaimsForFingerprint(?string $normalizedSerial, ?string $normalizedMac, bool $forUpdate = false): Collection
    {
        $query = AccessPointClaim::query()
            ->whereIn('claim_status', AccessPointClaim::openStatuses())
            ->where(function ($builder) use ($normalizedSerial, $normalizedMac): void {
                if ($normalizedSerial) {
                    $builder->orWhere('requested_serial_number_normalized', $normalizedSerial);
                }

                if ($normalizedMac) {
                    $builder->orWhere('requested_mac_address_normalized', $normalizedMac);
                }
            });

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function normalizeSerial(?string $serial): ?string
    {
        $serial = $this->stringOrNull($serial);

        return $serial ? strtoupper($serial) : null;
    }

    private function normalizeMac(?string $mac): ?string
    {
        $mac = $this->stringOrNull($mac);

        if (! $mac) {
            return null;
        }

        $stripped = preg_replace('/[^a-fA-F0-9]/', '', $mac) ?? '';

        if (strlen($stripped) !== 12) {
            throw ValidationException::withMessages([
                'requested_mac_address' => 'MAC address must contain 12 hexadecimal characters.',
            ]);
        }

        return strtoupper(implode(':', str_split($stripped, 2)));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
