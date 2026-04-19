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

class PortalBootstrapController extends Controller
{
    public function __invoke(Request $request, OmadaService $omadaService, PortalTokenService $portalTokenService): JsonResponse
    {
        $resolvedClientIp = $this->firstFilled($request, ['clientIp', 'client_ip']) ?: $request->ip();
        $macAddress = null;
        $queryMacAddress = $this->normalizeMac(
            $this->firstFilled($request, ['clientMac', 'client_mac', 'mac_address', 'mac'])
        );

        if (config('portal.allow_query_mac_fallback', false) && $queryMacAddress) {
            $macAddress = $queryMacAddress;
        } else {
            $controllerSettings = ControllerSetting::singleton();

            if ($controllerSettings->canTestConnection()) {
                $macAddress = $omadaService->getClientMacAddress($controllerSettings, $resolvedClientIp);
            }
        }

        $existingClient = $macAddress ? Client::findByMacAddress($macAddress) : null;
        $activeSession = $macAddress ? $this->findActiveSession($macAddress) : null;
        $portalContext = [
            'mac_address' => $macAddress,
            'ap_mac' => $this->firstFilled($request, ['apMac', 'ap_mac']),
            'ap_name' => $this->firstFilled($request, ['apName', 'ap_name']),
            'site_name' => $this->firstFilled($request, ['siteName', 'site_name', 'site']),
            'ssid_name' => $this->firstFilled($request, ['ssidName', 'ssid_name', 'ssid']),
            'radio_id' => $this->firstFilled($request, ['radioId', 'radio_id']),
            'client_ip' => $resolvedClientIp,
        ];

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
