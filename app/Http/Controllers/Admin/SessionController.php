<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WifiSession;
use Carbon\CarbonInterface;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', WifiSession::class);

        return Inertia::render('Admin/Sessions', [
            'sessions' => WifiSession::query()
                ->with([
                    'client:id,name,phone_number,mac_address',
                    'plan:id,name,price,duration_minutes',
                    'site:id,name',
                    'accessPoint:id,site_id,name,mac_address',
                ])
                ->latest()
                ->paginate(25)
                ->through(function (WifiSession $session): array {
                    return [
                        'id' => $session->id,
                        'client' => $session->client ? [
                            'id' => $session->client->id,
                            'name' => $session->client->name,
                            'phone_number' => $session->client->phone_number,
                            'mac_address' => $session->client->mac_address,
                        ] : null,
                        'mac_address' => $session->mac_address,
                        'site' => $session->site ? [
                            'id' => $session->site->id,
                            'name' => $session->site->name,
                        ] : null,
                        'access_point' => $session->accessPoint ? [
                            'id' => $session->accessPoint->id,
                            'name' => $session->accessPoint->name,
                            'mac_address' => $session->accessPoint->mac_address,
                        ] : null,
                        'ap_name' => $session->ap_name,
                        'ap_mac' => $session->ap_mac,
                        'ssid_name' => $session->ssid_name,
                        'plan' => $session->plan ? [
                            'id' => $session->plan->id,
                            'name' => $session->plan->name,
                            'price' => $session->plan->price,
                            'duration_minutes' => $session->plan->duration_minutes,
                        ] : null,
                        'payment_status' => $session->payment_status,
                        'is_active' => $session->is_active,
                        'start_time' => optional($session->start_time)?->toDateTimeString(),
                        'end_time' => optional($session->end_time)?->toDateTimeString(),
                        'remaining_time' => $this->formatRemainingTime($session),
                    ];
                })
                ->withQueryString(),
        ]);
    }

    private function formatRemainingTime(WifiSession $session): string
    {
        if (! $session->end_time instanceof CarbonInterface) {
            return '-';
        }

        $seconds = now()->diffInSeconds($session->end_time, false);

        if ($seconds <= 0) {
            return 'Expired';
        }

        if (! $session->is_active) {
            return 'Inactive';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        $parts = [];

        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }

        if ($minutes > 0 || $hours > 0) {
            $parts[] = "{$minutes}m";
        }

        $parts[] = "{$remainingSeconds}s";

        return implode(' ', $parts);
    }
}
