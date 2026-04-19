<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ControllerSetting;
use App\Services\OmadaService;
use App\Support\PortalTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalBootstrapController extends Controller
{
    public function __invoke(Request $request, OmadaService $omadaService, PortalTokenService $portalTokenService): JsonResponse
    {
        $macAddress = null;

        $controllerSettings = ControllerSetting::singleton();

        if ($controllerSettings->canTestConnection()) {
            $macAddress = $omadaService->getClientMacAddress($controllerSettings, $request->ip());
        }

        $existingClient = $macAddress ? Client::findByMacAddress($macAddress) : null;
        $portalContext = [
            'mac_address' => $macAddress,
            'ap_mac' => $this->firstFilled($request, ['apMac', 'ap_mac']),
            'ap_name' => $this->firstFilled($request, ['apName', 'ap_name']),
            'site_name' => $this->firstFilled($request, ['siteName', 'site_name', 'site']),
            'ssid_name' => $this->firstFilled($request, ['ssidName', 'ssid_name', 'ssid']),
            'radio_id' => $this->firstFilled($request, ['radioId', 'radio_id']),
            'client_ip' => $this->firstFilled($request, ['clientIp', 'client_ip']) ?: $request->ip(),
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
            ],
        ]);
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
}
