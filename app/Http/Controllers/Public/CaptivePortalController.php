<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\Plan;
use App\Services\OmadaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CaptivePortalController extends Controller
{
    public function __invoke(Request $request, OmadaService $omadaService): Response
    {
        // Get MAC address from request params first
        $macAddress = $this->firstFilled($request, ['clientMac', 'client_mac', 'mac_address', 'mac']);
        
        // If no MAC address, try to get it from Omada API
        if (!$macAddress) {
            $clientIp = $request->ip();
            $controllerSettings = ControllerSetting::singleton();
            
            if ($controllerSettings->canTestConnection()) {
                $macAddress = $omadaService->getClientMacAddress($controllerSettings, $clientIp);
            }
        }

        // Check if client already exists
        $existingClient = null;
        if ($macAddress) {
            $existingClient = Client::findByMacAddress($macAddress);
        }

        return Inertia::render('Public/PlanSelection', [
            'plans' => Plan::query()->orderBy('price')->get(),
            'portalContext' => [
                'mac_address' => $macAddress,
                'ap_mac' => $this->firstFilled($request, ['apMac', 'ap_mac']),
                'ap_name' => $this->firstFilled($request, ['apName', 'ap_name']),
                'site_name' => $this->firstFilled($request, ['siteName', 'site_name', 'site']),
                'ssid_name' => $this->firstFilled($request, ['ssidName', 'ssid_name', 'ssid']),
                'client_ip' => $this->firstFilled($request, ['clientIp', 'client_ip']) ?: $request->ip(),
            ],
            'existingClient' => $existingClient ? [
                'id' => $existingClient->id,
                'name' => $existingClient->name,
                'phone_number' => $existingClient->phone_number,
                'mac_address' => $existingClient->mac_address,
            ] : null,
        ]);
    }

    private function firstFilled(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) $request->query($key, ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
