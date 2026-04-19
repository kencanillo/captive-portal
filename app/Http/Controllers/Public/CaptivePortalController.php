<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CaptivePortalController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Public/PlanSelection', [
            'bootstrapUrl' => url('/api/portal/bootstrap').($request->getQueryString() ? "?{$request->getQueryString()}" : ''),
            'plansUrl' => url('/api/portal/plans').($request->getQueryString() ? "?{$request->getQueryString()}" : ''),
            'bootstrapTimeoutMs' => (int) config('portal.bootstrap_timeout_seconds', 8) * 1000,
            'initialPortalContext' => [
                'ap_mac' => $this->firstFilled($request, ['apMac', 'ap_mac']),
                'ap_name' => $this->firstFilled($request, ['apName', 'ap_name']),
                'site_name' => $this->firstFilled($request, ['siteName', 'site_name', 'site']),
                'ssid_name' => $this->firstFilled($request, ['ssidName', 'ssid_name', 'ssid']),
                'radio_id' => $this->firstFilled($request, ['radioId', 'radio_id']),
                'client_ip' => $this->firstFilled($request, ['clientIp', 'client_ip']) ?: $request->ip(),
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
