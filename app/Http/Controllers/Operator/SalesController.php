<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SalesController extends Controller
{
    public function index(Request $request): Response
    {
        $operator = $request->user()->loadMissing('operator.sites')->operator;

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $baseQuery = Payment::query()
            ->forOperator($operator)
            ->where('provider', Payment::PROVIDER_PAYMONGO)
            ->where('status', Payment::STATUS_PAID);

        $this->applyDateFilters($baseQuery, $filters);

        $sales = (clone $baseQuery)
            ->with([
                'wifiSession.client:id,name,phone_number,mac_address',
                'wifiSession.plan:id,name,price,duration_minutes',
                'wifiSession.site:id,name',
                'wifiSession.accessPoint:id,name,mac_address',
            ])
            ->orderByRaw('COALESCE(payments.paid_at, payments.created_at) DESC')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $summary = (clone $baseQuery)
            ->join('wifi_sessions', 'payments.wifi_session_id', '=', 'wifi_sessions.id')
            ->selectRaw('COALESCE(SUM(COALESCE(payments.amount, wifi_sessions.amount_paid)), 0) as total_sales')
            ->selectRaw('COUNT(*) as paid_payments_count')
            ->selectRaw('COUNT(DISTINCT wifi_sessions.client_id) as unique_clients_count')
            ->first();

        $dailySales = (clone $baseQuery)
            ->join('wifi_sessions', 'payments.wifi_session_id', '=', 'wifi_sessions.id')
            ->selectRaw('DATE(COALESCE(payments.paid_at, payments.created_at)) as date')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(COALESCE(payments.amount, wifi_sessions.amount_paid)), 0) as total')
            ->groupByRaw('DATE(COALESCE(payments.paid_at, payments.created_at))')
            ->orderByDesc('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'count' => (int) $row->count,
                'total' => number_format((float) $row->total, 2, '.', ''),
            ]);

        $accessPointSales = (clone $baseQuery)
            ->join('wifi_sessions', 'payments.wifi_session_id', '=', 'wifi_sessions.id')
            ->leftJoin('access_points', 'wifi_sessions.access_point_id', '=', 'access_points.id')
            ->selectRaw("COALESCE(access_points.name, wifi_sessions.ap_name, 'Unassigned AP') as access_point_name")
            ->selectRaw('COALESCE(access_points.mac_address, wifi_sessions.ap_mac) as access_point_mac')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(COALESCE(payments.amount, wifi_sessions.amount_paid)), 0) as total')
            ->groupByRaw("COALESCE(access_points.id, wifi_sessions.ap_mac, 'unassigned')")
            ->groupByRaw("COALESCE(access_points.name, wifi_sessions.ap_name, 'Unassigned AP')")
            ->groupByRaw('COALESCE(access_points.mac_address, wifi_sessions.ap_mac)')
            ->orderByDesc(DB::raw('COALESCE(SUM(COALESCE(payments.amount, wifi_sessions.amount_paid)), 0)'))
            ->get()
            ->map(fn ($row) => [
                'access_point_name' => $row->access_point_name,
                'access_point_mac' => $row->access_point_mac,
                'count' => (int) $row->count,
                'total' => number_format((float) $row->total, 2, '.', ''),
            ])
            ->values();

        return Inertia::render('Operator/Sales', [
            'filters' => [
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
            ],
            'summary' => [
                'total_sales' => number_format((float) ($summary->total_sales ?? 0), 2, '.', ''),
                'paid_payments_count' => (int) ($summary->paid_payments_count ?? 0),
                'unique_clients_count' => (int) ($summary->unique_clients_count ?? 0),
                'unique_access_points_count' => $accessPointSales->count(),
            ],
            'dailySales' => $dailySales,
            'accessPointSales' => $accessPointSales,
            'sales' => $sales->through(fn (Payment $payment) => [
                'id' => $payment->id,
                'wifi_session_id' => $payment->wifi_session_id,
                'reference_id' => $payment->reference_id,
                'provider' => $payment->provider,
                'status' => $payment->status,
                'amount' => number_format($this->paymentAmount($payment), 2, '.', ''),
                'currency' => $payment->currency,
                'paid_at' => optional($payment->paid_at)?->toDateTimeString(),
                'created_at' => optional($payment->created_at)?->toDateTimeString(),
                'client' => $payment->wifiSession?->client ? [
                    'id' => $payment->wifiSession->client->id,
                    'name' => $payment->wifiSession->client->name,
                    'phone_number' => $payment->wifiSession->client->phone_number,
                    'mac_address' => $payment->wifiSession->client->mac_address,
                ] : null,
                'mac_address' => $payment->wifiSession?->mac_address,
                'plan' => $payment->wifiSession?->plan ? [
                    'id' => $payment->wifiSession->plan->id,
                    'name' => $payment->wifiSession->plan->name,
                ] : null,
                'site' => $payment->wifiSession?->site ? [
                    'id' => $payment->wifiSession->site->id,
                    'name' => $payment->wifiSession->site->name,
                ] : null,
                'access_point' => $payment->wifiSession?->accessPoint ? [
                    'id' => $payment->wifiSession->accessPoint->id,
                    'name' => $payment->wifiSession->accessPoint->name,
                    'mac_address' => $payment->wifiSession->accessPoint->mac_address,
                ] : null,
                'ap_name' => $payment->wifiSession?->ap_name,
                'ap_mac' => $payment->wifiSession?->ap_mac,
            ]),
        ]);
    }

    private function applyDateFilters(Builder $query, array $filters): void
    {
        if ($filters['date_from'] ?? null) {
            $query->where(function (Builder $query) use ($filters): void {
                $query->whereDate('payments.paid_at', '>=', $filters['date_from'])
                    ->orWhere(function (Builder $query) use ($filters): void {
                        $query->whereNull('payments.paid_at')
                            ->whereDate('payments.created_at', '>=', $filters['date_from']);
                    });
            });
        }

        if ($filters['date_to'] ?? null) {
            $query->where(function (Builder $query) use ($filters): void {
                $query->whereDate('payments.paid_at', '<=', $filters['date_to'])
                    ->orWhere(function (Builder $query) use ($filters): void {
                        $query->whereNull('payments.paid_at')
                            ->whereDate('payments.created_at', '<=', $filters['date_to']);
                    });
            });
        }
    }

    private function paymentAmount(Payment $payment): float
    {
        return (float) ($payment->amount ?? $payment->wifiSession?->amount_paid ?? 0);
    }
}
