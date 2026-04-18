<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CaptivePortalController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Public/PlanSelection', [
            'plans' => Plan::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('price')
                ->get()
                ->map(fn (Plan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'price' => $plan->price,
                    'duration_minutes' => $plan->duration_minutes,
                    'data_limit_mb' => $plan->data_limit_mb,
                    'download_speed_kbps' => $plan->download_speed_kbps,
                    'upload_speed_kbps' => $plan->upload_speed_kbps,
                    'is_active' => $plan->is_active,
                ]),
            'bootstrapUrl' => url('/api/portal/bootstrap').($request->getQueryString() ? "?{$request->getQueryString()}" : ''),
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
