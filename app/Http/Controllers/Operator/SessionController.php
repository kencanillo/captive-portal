<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\WifiSession;
use App\Services\WifiSessionReleaseService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function index(Request $request, WifiSessionReleaseService $wifiSessionReleaseService): Response
    {
        $operator = $request->user()->loadMissing('operator.sites')->operator;

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'client' => ['nullable', 'string', 'max:120'],
            'access_point_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:60'],
        ]);

        $operatorAccessPoints = AccessPoint::query()
            ->forOperator($operator)
            ->orderBy('name')
            ->get(['id', 'name', 'mac_address']);

        $selectedAccessPointId = (int) ($filters['access_point_id'] ?? 0);

        if ($selectedAccessPointId > 0 && ! $operatorAccessPoints->contains('id', $selectedAccessPointId)) {
            abort(404);
        }

        $sessions = WifiSession::query()
            ->forOperator($operator)
            ->with([
                'client:id,name,phone_number,mac_address',
                'plan:id,name,price,duration_minutes',
                'site:id,name',
                'accessPoint:id,site_id,name,mac_address',
                'accessPoint.site:id,name',
            ])
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['client'] ?? null, function (Builder $query, string $client): void {
                $search = trim($client);

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('mac_address', 'like', "%{$search}%")
                        ->orWhereHas('client', function (Builder $clients) use ($search): void {
                            $clients->where('name', 'like', "%{$search}%")
                                ->orWhere('phone_number', 'like', "%{$search}%")
                                ->orWhere('mac_address', 'like', "%{$search}%");
                        });
                });
            })
            ->when($selectedAccessPointId > 0, fn (Builder $query) => $query->where('access_point_id', $selectedAccessPointId))
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                $query->where(function (Builder $query) use ($status): void {
                    $query->where('session_status', $status)
                        ->orWhere('payment_status', $status);
                });
            })
            ->orderByDesc('is_active')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $clientIds = $sessions->getCollection()
            ->pluck('client_id')
            ->filter()
            ->unique()
            ->values();

        $clientHistories = WifiSession::query()
            ->forOperator($operator)
            ->with([
                'plan:id,name',
                'site:id,name',
                'payments:id,wifi_session_id,reference_id,status,created_at',
            ])
            ->whereIn('client_id', $clientIds)
            ->latest()
            ->get()
            ->groupBy('client_id')
            ->map(function ($history) {
                return $history
                    ->take(8)
                    ->map(function (WifiSession $session) {
                        return [
                            'id' => $session->id,
                            'plan_name' => $session->plan?->name,
                            'site_name' => $session->site?->name,
                            'payment_status' => $session->payment_status,
                            'release_status' => $session->release_status,
                            'remaining_time' => $this->formatRemainingTime($session),
                            'created_at' => optional($session->created_at)?->toDateTimeString(),
                            'end_time' => optional($session->end_time)?->toDateTimeString(),
                            'payments' => $session->payments->take(3)->map(fn ($payment) => [
                                'id' => $payment->id,
                                'reference_id' => $payment->reference_id,
                                'status' => $payment->status,
                                'created_at' => optional($payment->created_at)?->toDateTimeString(),
                            ])->values()->all(),
                        ];
                    })
                    ->values()
                    ->all();
            });

        return Inertia::render('Operator/Sessions', [
            'releaseRuntime' => $wifiSessionReleaseService->runtimeHealthForOperator($operator),
            'clientHistories' => $clientHistories,
            'filters' => [
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'client' => $filters['client'] ?? '',
                'access_point_id' => $selectedAccessPointId ?: '',
                'status' => $filters['status'] ?? '',
            ],
            'accessPoints' => $operatorAccessPoints->map(fn (AccessPoint $accessPoint) => [
                'id' => $accessPoint->id,
                'name' => $accessPoint->name,
                'mac_address' => $accessPoint->mac_address,
            ]),
            'sessions' => $sessions->through(function (WifiSession $session): array {
                return [
                    'id' => $session->id,
                    'client_id' => $session->client_id,
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
                    'session_status' => $session->session_status,
                    'release_status' => $session->release_status,
                    'release_outcome_type' => $session->release_outcome_type,
                    'release_attempt_count' => $session->release_attempt_count,
                    'last_release_attempt_at' => optional($session->last_release_attempt_at)?->toDateTimeString(),
                    'last_release_error' => $session->last_release_error,
                    'controller_state_uncertain' => $session->controller_state_uncertain,
                    'released_at' => optional($session->released_at)?->toDateTimeString(),
                    'last_reconciled_at' => optional($session->last_reconciled_at)?->toDateTimeString(),
                    'reconcile_attempt_count' => $session->reconcile_attempt_count,
                    'last_reconcile_result' => $session->last_reconcile_result,
                    'controller_check_message' => $session->last_reconcile_result === 'not_authorized_in_controller'
                        ? 'Controller inspection confirmed the client is not currently authorized.'
                        : ($session->last_reconcile_result === 'reconcile_failed'
                            ? 'Controller verification failed. Check the controller connection before trusting this record.'
                            : null),
                    'release_stuck_at' => optional($session->release_stuck_at)?->toDateTimeString(),
                    'manual_followup_required' => $session->release_status === WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
                    'released_by_path' => $session->released_by_path,
                    'release_metadata' => $session->release_metadata,
                    'is_active' => $session->is_active,
                    'start_time' => optional($session->start_time)?->toDateTimeString(),
                    'end_time' => optional($session->end_time)?->toDateTimeString(),
                    'remaining_time' => $this->formatRemainingTime($session),
                ];
            }),
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
