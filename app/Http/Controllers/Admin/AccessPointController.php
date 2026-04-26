<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\ControllerSetting;
use App\Models\Operator;
use App\Models\Site;
use App\Services\AccessPointBillingService;
use App\Services\AccessPointClaimService;
use App\Services\AccessPointHealthService;
use App\Services\OmadaService;
use App\Services\OperationalReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccessPointController extends Controller
{
    public function index(): Response
    {
        $settings = ControllerSetting::singleton();
        $claimService = app(AccessPointClaimService::class);
        $healthService = app(AccessPointHealthService::class);
        $billingService = app(AccessPointBillingService::class);
        $attentionCount = AccessPoint::query()
            ->where(function ($query): void {
                $query->where('claim_status', AccessPoint::CLAIM_STATUS_ERROR)
                    ->orWhere('adoption_state', AccessPoint::ADOPTION_STATE_ADOPTION_FAILED)
                    ->orWhereIn('health_state', [
                        AccessPoint::HEALTH_STATE_HEARTBEAT_MISSED,
                        AccessPoint::HEALTH_STATE_DISCONNECTED,
                        AccessPoint::HEALTH_STATE_STALE_UNKNOWN,
                    ]);
            })
            ->count();
        $accessPoints = AccessPoint::query()
            ->with([
                'site:id,name',
                'claimedByOperator:id,business_name',
                'approvedClaim:id,claim_status',
                'ownershipCorrectedBy:id,name,email',
                'latestBillingEntry',
            ])
            ->orderByRaw("health_state = 'connected' desc")
            ->orderByRaw("claim_status = 'claimed' desc")
            ->orderBy('name')
            ->get()
            ->map(function (AccessPoint $accessPoint) use ($healthService, $billingService) {
                $health = $healthService->present($accessPoint);
                $billing = $billingService->present($accessPoint);

                return [
                    'id' => $accessPoint->id,
                    'name' => $accessPoint->name,
                    'serial_number' => $accessPoint->serial_number,
                    'mac_address' => $accessPoint->mac_address,
                    'site_name' => $accessPoint->site?->name,
                    'vendor' => $accessPoint->vendor,
                    'model' => $accessPoint->model,
                    'ip_address' => $accessPoint->ip_address,
                    'omada_device_id' => $accessPoint->omada_device_id,
                    'claim_status' => $accessPoint->claim_status,
                    'adoption_state' => $accessPoint->adoption_state,
                    'claimed_at' => optional($accessPoint->claimed_at)?->toDateTimeString(),
                    'claimed_by_operator' => $accessPoint->claimedByOperator?->business_name,
                    'approved_claim_id' => $accessPoint->approved_claim_id,
                    'ownership_corrected_at' => optional($accessPoint->ownership_corrected_at)?->toDateTimeString(),
                    'ownership_corrected_by' => $accessPoint->ownershipCorrectedBy?->name,
                    'latest_correction_reason' => $accessPoint->latest_correction_reason,
                    'last_seen_at' => optional($accessPoint->last_seen_at)?->toDateTimeString(),
                    'last_synced_at' => optional($accessPoint->last_synced_at)?->toDateTimeString(),
                    'is_online' => $accessPoint->is_online,
                    'status_label' => $this->statusLabel($accessPoint),
                    'health' => $health,
                    'billing' => $billing,
                ];
            });

        return Inertia::render('Admin/AccessPoints', [
            'syncConfigured' => $settings->canSyncAccessPoints(),
            'syncHealth' => $claimService->inventoryHealth(),
            'healthRuntime' => $healthService->runtimeHealth(),
            'billingRuntime' => $billingService->runtimeHealth(),
            'webhookCapabilityVerdict' => $healthService->webhookCapabilityVerdict(),
            'statusSummary' => [
                'connected' => AccessPoint::query()->where('health_state', AccessPoint::HEALTH_STATE_CONNECTED)->count(),
                'pending' => AccessPoint::query()
                    ->where('health_state', AccessPoint::HEALTH_STATE_PENDING)
                    ->count(),
                'attention' => $attentionCount,
                'claimed' => AccessPoint::query()->whereNotNull('claimed_by_operator_id')->count(),
                'billed' => AccessPoint::query()->where('billing_state', AccessPoint::BILLING_STATE_BILLED)->count(),
                'blocked_billing' => AccessPoint::query()->where('billing_state', AccessPoint::BILLING_STATE_BLOCKED)->count(),
                'billing_manual_review' => AccessPoint::query()->where('billing_incident_state', AccessPoint::BILLING_INCIDENT_MANUAL_REVIEW_REQUIRED)->count(),
            ],
            'operators' => Operator::query()
                ->orderBy('business_name')
                ->get(['id', 'business_name'])
                ->map(fn (Operator $operator) => [
                    'id' => $operator->id,
                    'business_name' => $operator->business_name,
                ]),
            'sites' => Site::query()
                ->orderBy('name')
                ->get(['id', 'name', 'operator_id'])
                ->map(fn (Site $site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'operator_id' => $site->operator_id,
                ]),
            'accessPoints' => $accessPoints,
        ]);
    }

    public function correctOwnership(Request $request, AccessPoint $accessPoint, AccessPointClaimService $claimService): RedirectResponse
    {
        $validated = $request->validate([
            'operator_id' => ['required', 'integer', 'exists:operators,id'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'correction_reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $claimService->correctOwnership($accessPoint, $request->user(), $validated);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.access-points.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.access-points.index')
            ->with('success', 'AP ownership corrected successfully.');
    }

    public function sync(OmadaService $omadaService)
    {
        $settings = ControllerSetting::query()->first();

        if (! $settings) {
            return redirect()
                ->route('admin.access-points.index')
                ->with('error', 'Controller settings are missing. Save them before syncing devices.');
        }

        if (! $settings->canSyncAccessPoints()) {
            return redirect()
                ->route('admin.access-points.index')
                ->with('error', 'Omada AP sync requires OpenAPI client credentials. Save them in Controller Settings before syncing devices.');
        }

        try {
            $result = $omadaService->syncAccessPoints($settings);
            $settings->forceFill([
                'last_tested_at' => now(),
            ])->save();

            return redirect()
                ->route('admin.access-points.index')
                ->with(
                    'success',
                    "Omada sync finished. {$result['total']} devices scanned, {$result['claimed']} claimed, {$result['pending']} pending, {$result['created']} created, {$result['updated']} updated."
                );
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.access-points.index')
                ->with('error', $exception->getMessage());
        }
    }

    public function postConnectionFees(
        Request $request,
        AccessPointBillingService $billingService,
        OperationalReadinessService $operationalReadinessService,
    ): RedirectResponse
    {
        try {
            $operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_BILLING_POST);
            $result = $billingService->postConnectionFees($request->user(), \App\Models\BillingLedgerEntry::SOURCE_ADMIN_RUN);

            return redirect()
                ->route('admin.access-points.index')
                ->with(
                    'success',
                    "AP billing finished. {$result['posted']} posted, {$result['already_billed']} already billed, {$result['blocked']} blocked, {$result['reversed']} reversed, {$result['unbilled']} unbilled."
                );
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.access-points.index')
                ->with('error', $exception->getMessage());
        }
    }

    public function reverseConnectionFee(Request $request, AccessPoint $accessPoint, AccessPointBillingService $billingService): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $billingService->reverseConnectionFee(
                $accessPoint,
                $request->user(),
                $validated['reason'],
                $validated['notes'] ?? null,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.access-points.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.access-points.index')
            ->with('success', 'AP connection fee reversed with a compensating credit.');
    }

    public function resolveBillingIncident(
        Request $request,
        AccessPoint $accessPoint,
        AccessPointBillingService $billingService,
        OperationalReadinessService $operationalReadinessService,
    ): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:confirm_eligibility,authorize_repost'],
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_BILLING_RESOLUTION);
            $billingService->resolveBillingIncident(
                $accessPoint,
                $request->user(),
                $validated['action'],
                $validated['reason'],
                $validated['notes'] ?? null,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.access-points.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.access-points.index')
            ->with('success', 'Billing incident resolved. Review the AP billing state before posting fees again.');
    }

    private function statusLabel(AccessPoint $accessPoint): string
    {
        if ($accessPoint->claim_status === AccessPoint::CLAIM_STATUS_ERROR) {
            return 'Failed';
        }

        if ($accessPoint->adoption_state === AccessPoint::ADOPTION_STATE_ADOPTION_FAILED) {
            return 'Failed';
        }

        if ($accessPoint->claim_status === AccessPoint::CLAIM_STATUS_PENDING) {
            return 'Pending';
        }

        return match ($accessPoint->health_state) {
            AccessPoint::HEALTH_STATE_CONNECTED => 'Connected',
            AccessPoint::HEALTH_STATE_HEARTBEAT_MISSED => 'Heartbeat Missed',
            AccessPoint::HEALTH_STATE_DISCONNECTED => 'Disconnected',
            AccessPoint::HEALTH_STATE_STALE_UNKNOWN => 'Stale Unknown',
            AccessPoint::HEALTH_STATE_PENDING => 'Pending',
            default => 'Unknown',
        };
    }
}
