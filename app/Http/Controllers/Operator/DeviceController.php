<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\AccessPointClaim;
use App\Models\ControllerSetting;
use App\Models\WifiSession;
use App\Services\AccessPointBillingService;
use App\Services\AccessPointHealthService;
use App\Services\OmadaService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DeviceController extends Controller
{
    public function index(): Response
    {
        $operator = request()->user()->loadMissing('operator.sites')->operator;
        $claimService = app(\App\Services\AccessPointClaimService::class);
        $healthService = app(AccessPointHealthService::class);
        $billingService = app(AccessPointBillingService::class);
        $settings = ControllerSetting::singleton();

        $currentSessions = WifiSession::query()
            ->forOperator($operator)
            ->with(['site:id,name', 'accessPoint:id,site_id,name,mac_address'])
            ->whereIn('session_status', [
                WifiSession::SESSION_STATUS_PENDING_PAYMENT,
                WifiSession::SESSION_STATUS_PAID,
                WifiSession::SESSION_STATUS_ACTIVE,
                WifiSession::SESSION_STATUS_RELEASE_FAILED,
            ])
            ->whereIn('payment_status', [
                WifiSession::PAYMENT_STATUS_PENDING,
                WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
                WifiSession::PAYMENT_STATUS_PAID,
            ])
            ->get();

        $accessPoints = AccessPoint::query()
            ->forOperator($operator)
            ->with(['site:id,name', 'latestBillingEntry'])
            ->orderBy('name')
            ->get();

        $knownMacs = $accessPoints
            ->pluck('mac_address')
            ->filter()
            ->map(fn (string $macAddress) => strtolower($macAddress))
            ->all();

        $observedDevices = $this->observedDevicesFromSessions($currentSessions, $knownMacs);

        $connectedDevices = $accessPoints
            ->filter(fn (AccessPoint $ap) => $this->isConnected($ap, $currentSessions))
            ->map(fn (AccessPoint $ap) => $this->presentAccessPoint($ap, $currentSessions, $healthService, $billingService))
            ->values()
            ->merge($observedDevices)
            ->values();

        $failedDevices = $accessPoints
            ->reject(fn (AccessPoint $ap) => $this->isConnected($ap, $currentSessions))
            ->map(fn (AccessPoint $ap) => $this->presentAccessPoint($ap, $currentSessions, $healthService, $billingService))
            ->values();

        return Inertia::render('Operator/Devices', [
            'syncConfigured' => $settings->canSyncAccessPoints(),
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

    public function sync(OmadaService $omadaService): RedirectResponse
    {
        $settings = ControllerSetting::query()->first();

        if (! $settings) {
            return redirect()
                ->route('operator.devices.index')
                ->with('error', 'Controller settings are missing. Ask an admin to save them before syncing AP inventory.');
        }

        if (! $settings->canSyncAccessPoints()) {
            return redirect()
                ->route('operator.devices.index')
                ->with('error', 'AP sync requires controller OpenAPI credentials. Ask an admin to complete Controller Settings.');
        }

        try {
            $result = $omadaService->syncAccessPoints($settings);
            $settings->forceFill([
                'last_tested_at' => now(),
            ])->save();

            return redirect()
                ->route('operator.devices.index')
                ->with('success', "AP sync finished. {$result['total']} devices scanned.");
        } catch (Throwable $exception) {
            return redirect()
                ->route('operator.devices.index')
                ->with('error', $exception->getMessage());
        }
    }

    private function presentAccessPoint(
        AccessPoint $accessPoint,
        $currentSessions,
        AccessPointHealthService $healthService,
        AccessPointBillingService $billingService,
    ): array {
        return [
            'id' => $accessPoint->id,
            'name' => $accessPoint->name,
            'mac_address' => $accessPoint->mac_address,
            'model' => $accessPoint->model,
            'site_name' => $accessPoint->site?->name,
            'claim_status' => $accessPoint->claim_status,
            'current_sessions_count' => $this->currentSessionCount($accessPoint, $currentSessions),
            'last_synced_at' => optional($accessPoint->last_synced_at)?->toDateTimeString(),
            'health' => $healthService->present($accessPoint),
            'billing' => $billingService->present($accessPoint),
            'source' => 'inventory',
        ];
    }

    private function observedDevicesFromSessions($currentSessions, array $knownMacs)
    {
        return $currentSessions
            ->filter(fn (WifiSession $session) => filled($session->ap_mac)
                && ! in_array(strtolower($session->ap_mac), $knownMacs, true))
            ->groupBy(fn (WifiSession $session) => strtolower($session->ap_mac))
            ->map(function ($sessions, string $macAddress): array {
                /** @var WifiSession $first */
                $first = $sessions->sortByDesc('updated_at')->first();

                return [
                    'id' => 'observed-'.$macAddress,
                    'name' => $first->ap_name ?: $first->ap_mac,
                    'mac_address' => $first->ap_mac,
                    'model' => null,
                    'site_name' => $first->site?->name,
                    'claim_status' => 'observed',
                    'current_sessions_count' => $sessions->pluck('id')->unique()->count(),
                    'last_synced_at' => null,
                    'health' => [
                        'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
                        'health_label' => 'Connected',
                        'health_checked_at' => optional($first->updated_at)?->toDateTimeString(),
                        'status_source' => 'session',
                        'status_source_event_at' => optional($first->updated_at)?->toDateTimeString(),
                        'freshness_seconds' => max(0, now()->diffInSeconds($first->updated_at)),
                        'freshness_label' => 'session observed',
                        'is_fresh' => true,
                        'health_confidence' => 'observed',
                        'last_connected_at' => optional($first->updated_at)?->toDateTimeString(),
                        'first_confirmed_connected_at' => null,
                        'last_disconnected_at' => null,
                        'last_health_mismatch_at' => null,
                        'health_warning' => null,
                    ],
                    'billing' => null,
                    'source' => 'session',
                ];
            })
            ->values();
    }

    private function isConnected(AccessPoint $accessPoint, $currentSessions): bool
    {
        return $accessPoint->health_state === AccessPoint::HEALTH_STATE_CONNECTED
            || $accessPoint->is_online
            || $this->currentSessionCount($accessPoint, $currentSessions) > 0;
    }

    private function currentSessionCount(AccessPoint $accessPoint, $currentSessions): int
    {
        $macAddress = strtolower((string) $accessPoint->mac_address);

        return $currentSessions
            ->filter(fn (WifiSession $session) => (int) $session->access_point_id === (int) $accessPoint->id
                || (filled($session->ap_mac) && strtolower($session->ap_mac) === $macAddress))
            ->pluck('id')
            ->unique()
            ->count();
    }
}
