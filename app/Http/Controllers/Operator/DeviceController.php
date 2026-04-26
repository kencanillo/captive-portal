<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\AccessPointClaim;
use App\Models\WifiSession;
use App\Services\AccessPointBillingService;
use App\Services\AccessPointHealthService;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    public function index(): Response
    {
        $operator = request()->user()->loadMissing('operator.sites')->operator;
        $claimService = app(\App\Services\AccessPointClaimService::class);
        $healthService = app(AccessPointHealthService::class);
        $billingService = app(AccessPointBillingService::class);

        $connectedDevices = AccessPoint::query()
            ->forOperator($operator)
            ->where(function ($query): void {
                $query->where('health_state', AccessPoint::HEALTH_STATE_CONNECTED)
                    ->orWhere(function ($query): void {
                        $query->whereNull('health_state')->where('is_online', true);
                    });
            })
            ->with(['site:id,name', 'latestBillingEntry'])
            ->withCount([
                'wifiSessions as current_sessions_count' => fn ($query) => $query->whereIn('session_status', [
                    WifiSession::SESSION_STATUS_PENDING_PAYMENT,
                    WifiSession::SESSION_STATUS_PAID,
                    WifiSession::SESSION_STATUS_ACTIVE,
                    WifiSession::SESSION_STATUS_RELEASE_FAILED,
                ])->whereIn('payment_status', [
                    WifiSession::PAYMENT_STATUS_PENDING,
                    WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
                    WifiSession::PAYMENT_STATUS_PAID,
                ]),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (AccessPoint $ap) => [
                'id' => $ap->id,
                'name' => $ap->name,
                'mac_address' => $ap->mac_address,
                'model' => $ap->model,
                'site_name' => $ap->site?->name,
                'claim_status' => $ap->claim_status,
                'current_sessions_count' => $ap->current_sessions_count,
                'last_synced_at' => optional($ap->last_synced_at)?->toDateTimeString(),
                'health' => $healthService->present($ap),
                'billing' => $billingService->present($ap),
            ]);

        $failedDevices = AccessPoint::query()
            ->forOperator($operator)
            ->where(function ($query): void {
                $query->whereIn('health_state', [
                    AccessPoint::HEALTH_STATE_HEARTBEAT_MISSED,
                    AccessPoint::HEALTH_STATE_DISCONNECTED,
                    AccessPoint::HEALTH_STATE_STALE_UNKNOWN,
                    AccessPoint::HEALTH_STATE_PENDING,
                ])->orWhere(function ($query): void {
                    $query->whereNull('health_state')->where('is_online', false);
                });
            })
            ->with(['site:id,name', 'latestBillingEntry'])
            ->withCount([
                'wifiSessions as current_sessions_count' => fn ($query) => $query->whereIn('session_status', [
                    WifiSession::SESSION_STATUS_PENDING_PAYMENT,
                    WifiSession::SESSION_STATUS_PAID,
                    WifiSession::SESSION_STATUS_ACTIVE,
                    WifiSession::SESSION_STATUS_RELEASE_FAILED,
                ])->whereIn('payment_status', [
                    WifiSession::PAYMENT_STATUS_PENDING,
                    WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
                    WifiSession::PAYMENT_STATUS_PAID,
                ]),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (AccessPoint $ap) => [
                'id' => $ap->id,
                'name' => $ap->name,
                'mac_address' => $ap->mac_address,
                'model' => $ap->model,
                'site_name' => $ap->site?->name,
                'claim_status' => $ap->claim_status,
                'current_sessions_count' => $ap->current_sessions_count,
                'last_synced_at' => optional($ap->last_synced_at)?->toDateTimeString(),
                'health' => $healthService->present($ap),
                'billing' => $billingService->present($ap),
            ]);

        return Inertia::render('Operator/Devices', [
            'syncHealth' => $claimService->inventoryHealth(),
            'healthRuntime' => $healthService->runtimeHealth(),
            'billingRuntime' => $billingService->runtimeHealth(),
            'webhookCapabilityVerdict' => $healthService->webhookCapabilityVerdict(),
            'claimableSites' => $operator->sites->map(fn ($site) => [
                'id' => $site->id,
                'name' => $site->name,
            ])->values(),
            'claimRequests' => AccessPointClaim::query()
                ->where('operator_id', $operator->id)
                ->with(['site:id,name', 'matchedAccessPoint.site:id,name'])
                ->latest('claimed_at')
                ->latest()
                ->get()
                ->map(fn (AccessPointClaim $claim) => [
                    'id' => $claim->id,
                    'claim_status' => $claim->claim_status,
                    'claim_match_status' => $claim->claim_match_status,
                    'requested_serial_number' => $claim->requested_serial_number,
                    'requested_mac_address' => $claim->requested_mac_address,
                    'requested_ap_name' => $claim->requested_ap_name,
                    'claimed_at' => optional($claim->claimed_at)?->toDateTimeString(),
                    'reviewed_at' => optional($claim->reviewed_at)?->toDateTimeString(),
                    'matched_at' => optional($claim->matched_at)?->toDateTimeString(),
                    'requires_re_review' => $claim->requires_re_review,
                    'conflict_state' => $claim->conflict_state,
                    'sync_freshness_checked_at' => optional($claim->sync_freshness_checked_at)?->toDateTimeString(),
                    'review_notes' => $claim->review_notes,
                    'denial_reason' => $claim->denial_reason,
                    'failure_reason' => $claim->failure_reason,
                    'site_name' => $claim->site?->name,
                    'matched_access_point' => $claim->matchedAccessPoint ? [
                        'id' => $claim->matchedAccessPoint->id,
                        'name' => $claim->matchedAccessPoint->name,
                        'mac_address' => $claim->matchedAccessPoint->mac_address,
                        'serial_number' => $claim->matchedAccessPoint->serial_number,
                        'site_name' => $claim->matchedAccessPoint->site?->name,
                    ] : null,
                ]),
            'connectedDevices' => $connectedDevices,
            'failedDevices' => $failedDevices,
        ]);
    }
}
