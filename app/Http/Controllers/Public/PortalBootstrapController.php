<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\WifiSession;
use App\Services\OmadaService;
use App\Support\PortalTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PortalBootstrapController extends Controller
{
    public function __invoke(Request $request, OmadaService $omadaService, PortalTokenService $portalTokenService): JsonResponse
    {
        $startedAt = microtime(true);
        $resolvedClientIp = $this->firstFilled($request, ['clientIp', 'client_ip']) ?: $request->ip();
        $macAddress = null;
        $resolutionSource = 'none';
        $queryMacAddress = $this->normalizeMac(
            $this->firstFilled($request, ['clientMac', 'client_mac', 'mac_address', 'mac'])
        );
        $existingClient = null;

        // Phase 1: Query parameter MAC resolution
        $phase1Start = microtime(true);
        if ($queryMacAddress) {
            $existingClient = Client::findByMacAddress($queryMacAddress);

            if ($existingClient) {
                $macAddress = $existingClient->mac_address;
                $resolutionSource = 'known_client_db';
            }
        }
        $phase1Duration = microtime(true) - $phase1Start;

        // Phase 2: Fallback or Omada lookup
        $phase2Start = microtime(true);
        if (! $macAddress && config('portal.allow_query_mac_fallback', false) && $queryMacAddress) {
            $macAddress = $queryMacAddress;
            $resolutionSource = 'query_fallback';
        } elseif (! $macAddress) {
            $controllerSettings = ControllerSetting::singleton();

            if ($controllerSettings->canTestConnection()) {
                $omadaLookupStart = microtime(true);
                $macAddress = $omadaService->getClientMacAddress($controllerSettings, $resolvedClientIp);
                $omadaLookupDuration = microtime(true) - $omadaLookupStart;
                $resolutionSource = $macAddress ? 'omada' : 'omada_miss';
                Log::info('Portal bootstrap Omada MAC lookup timing', [
                    'client_ip' => $resolvedClientIp,
                    'omada_lookup_duration_ms' => (int) round($omadaLookupDuration * 1000),
                    'total_bootstrap_duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
            }
        }
        $phase2Duration = microtime(true) - $phase2Start;

        // Phase 3: Session lookup
        $phase3Start = microtime(true);
        $existingClient ??= $macAddress ? Client::findByMacAddress($macAddress) : null;
        $activeSession = $macAddress ? $this->findActiveSession($macAddress) : null;
        $phase3Duration = microtime(true) - $phase3Start;
        $portalContext = [
            'mac_address' => $macAddress,
            'ap_mac' => $this->firstFilled($request, ['apMac', 'ap_mac']),
            'ap_name' => $this->firstFilled($request, ['apName', 'ap_name']),
            'site_name' => $this->firstFilled($request, ['siteName', 'site_name', 'site']),
            'ssid_name' => $this->firstFilled($request, ['ssidName', 'ssid_name', 'ssid']),
            'radio_id' => $this->firstFilled($request, ['radioId', 'radio_id']),
            'client_ip' => $resolvedClientIp,
        ];

        Log::info('Portal bootstrap resolved device context.', [
            'client_ip' => $resolvedClientIp,
            'resolution_source' => $resolutionSource,
            'has_mac_address' => filled($macAddress),
            'has_existing_client' => $existingClient !== null,
            'has_active_session' => $activeSession !== null,
            'phase1_db_lookup_ms' => (int) round($phase1Duration * 1000),
            'phase2_omada_lookup_ms' => (int) round($phase2Duration * 1000),
            'phase3_session_lookup_ms' => (int) round($phase3Duration * 1000),
            'total_duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return response()->json([
            'data' => [
                'portal_context' => $portalContext,
                'portal_token' => $macAddress
                    ? $portalTokenService->issuePortalContextToken($portalContext)
                    : null,
                'existing_client' => $existingClient ? [
                    'id' => $existingClient->id,
                    'name' => $existingClient->name,
                    'phone_number' => $existingClient->phone_number,
                    'mac_address' => $existingClient->mac_address,
                ] : null,
                'active_session' => $activeSession ? [
                    'id' => $activeSession->id,
                    'session_status' => $activeSession->session_status,
                    'payment_status' => $activeSession->payment_status,
                    'start_time' => $activeSession->start_time?->toIso8601String(),
                    'end_time' => $activeSession->end_time?->toIso8601String(),
                    'client_name' => $activeSession->client?->name,
                    'phone_number' => $activeSession->client?->phone_number,
                    'plan' => $activeSession->plan ? [
                        'id' => $activeSession->plan->id,
                        'name' => $activeSession->plan->name,
                        'duration_minutes' => $activeSession->plan->duration_minutes,
                    ] : null,
                ] : null,
            ],
        ]);
    }

    private function findActiveSession(string $macAddress): ?WifiSession
    {
        return WifiSession::query()
            ->with(['client:id,name,phone_number', 'plan:id,name,duration_minutes'])
            ->whereRaw('LOWER(mac_address) = ?', [strtolower($macAddress)])
            ->where('is_active', true)
            ->whereNotNull('end_time')
            ->where('end_time', '>', now())
            ->latest('end_time')
            ->first();
    }

    private function firstFilled(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $request->query($key);

            if ($value === null) {
                foreach ($request->query() as $queryKey => $queryValue) {
                    if (strtolower((string) $queryKey) === strtolower($key)) {
                        $value = $queryValue;
                        break;
                    }
                }
            }

            $value = trim((string) ($value ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeMac(?string $value): ?string
    {
        $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string) $value) ?? '');

        if (strlen($mac) !== 12) {
            return null;
        }

        return implode(':', str_split($mac, 2));
    }
}
