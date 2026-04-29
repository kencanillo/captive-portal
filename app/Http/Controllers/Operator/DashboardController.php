<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\Payment;
use App\Models\WifiSession;
use App\Services\AccessPointHealthService;
use App\Services\OperatorAccountingService;
use App\Services\OperatorPayoutService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        OperatorPayoutService $payoutService,
        OperatorAccountingService $operatorAccountingService,
        AccessPointHealthService $healthService,
    ): Response
    {
        $operator = request()->user()->loadMissing('operator.sites')->operator;
        $siteIds = $operator->sites()->pluck('id');
        $balance = $payoutService->summary($operator);
        $accounting = $operatorAccountingService->summary($operator);

        return Inertia::render('Operator/Dashboard', [
            'summary' => [
                'sites_count' => $siteIds->count(),
                'access_points_count' => AccessPoint::query()->forOperator($operator)->count(),
                'active_sessions_count' => WifiSession::query()->forOperator($operator)->where('is_active', true)->count(),
                'completed_payments_count' => Payment::query()->forOperator($operator)->where('status', Payment::STATUS_PAID)->count(),
                'gross_sales' => number_format((float) $accounting['gross_sales'], 2, '.', ''),
                'net_sales' => number_format((float) $accounting['net_sales'], 2, '.', ''),
                'paid_sales_count' => $accounting['paid_sales_count'],
                'payable_basis' => number_format((float) $accounting['payable_basis'], 2, '.', ''),
                'gross_billed_fees' => number_format((float) $accounting['gross_billed_fees'], 2, '.', ''),
                'reversed_fees' => number_format((float) $accounting['reversed_fees'], 2, '.', ''),
                'blocked_fees' => number_format((float) $accounting['blocked_fees'], 2, '.', ''),
                'net_payable_fees' => number_format((float) $accounting['net_payable_fees'], 2, '.', ''),
                'available_balance' => number_format((float) $balance['available_balance'], 2, '.', ''),
                'confidence_state' => $accounting['confidence_state'],
                'unresolved_blocked_count' => $accounting['unresolved_blocked_count'],
            ],
            'sites' => $operator->sites()
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->map(fn ($site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'slug' => $site->slug,
                ]),
            'recentSessions' => WifiSession::query()
                ->forOperator($operator)
                ->with([
                    'client:id,name,phone_number',
                    'plan:id,name',
                    'site:id,name',
                    'accessPoint:id,site_id,name,mac_address',
                    'accessPoint.site:id,name',
                ])
                ->latest('id')
                ->limit(6)
                ->get()
                ->map(fn (WifiSession $session) => [
                    'id' => $session->id,
                    'client_name' => $session->client?->name,
                    'plan_name' => $session->plan?->name,
                    'site_name' => $session->site?->name ?? $session->accessPoint?->site?->name,
                    'access_point_name' => $session->accessPoint?->name ?? $session->ap_name,
                    'access_point_mac' => $session->accessPoint?->mac_address ?? $session->ap_mac,
                    'payment_status' => $session->payment_status,
                    'session_status' => $session->session_status,
                    'amount_paid' => number_format((float) $session->amount_paid, 2, '.', ''),
                    'updated_at' => optional($session->updated_at)?->toDateTimeString(),
                ]),
            'recentPayments' => Payment::query()
                ->forOperator($operator)
                ->with([
                    'wifiSession.plan:id,name',
                    'wifiSession.site:id,name',
                    'wifiSession.accessPoint:id,site_id,name,mac_address',
                    'wifiSession.accessPoint.site:id,name',
                ])
                ->latest('id')
                ->limit(6)
                ->get()
                ->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'amount' => number_format((float) $payment->amount, 2, '.', ''),
                    'plan_name' => $payment->wifiSession?->plan?->name,
                    'site_name' => $payment->wifiSession?->site?->name ?? $payment->wifiSession?->accessPoint?->site?->name,
                    'access_point_name' => $payment->wifiSession?->accessPoint?->name ?? $payment->wifiSession?->ap_name,
                    'reference_id' => $payment->reference_id,
                    'updated_at' => optional($payment->updated_at)?->toDateTimeString(),
                ]),
            'accessPoints' => AccessPoint::query()
                ->forOperator($operator)
                ->with('site:id,name')
                ->withCount([
                    'wifiSessions as active_sessions_count' => fn ($query) => $query->where('is_active', true),
                    'wifiSessions as current_sessions_count' => fn ($query) => $query->whereIn('session_status', [
                        WifiSession::SESSION_STATUS_PENDING_PAYMENT,
                        WifiSession::SESSION_STATUS_PAID,
                        WifiSession::SESSION_STATUS_ACTIVE,
                        WifiSession::SESSION_STATUS_RELEASE_FAILED,
                    ])->whereIn('payment_status', [
                        WifiSession::PAYMENT_STATUS_PENDING,
                        WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
                        WifiSession::PAYMENT_STATUS_PAID,
                    ]),
                    'wifiSessions as paid_sessions_count' => fn ($query) => $query
                        ->where('payment_status', WifiSession::STATUS_PAID)
                        ->whereHas('latestPayment', fn ($q) => $q->where('status', '!=', Payment::STATUS_WAIVED)),
                ])
                ->withSum([
                    'wifiSessions as revenue_total' => fn ($query) => $query
                        ->where('payment_status', WifiSession::STATUS_PAID)
                        ->whereHas('latestPayment', fn ($q) => $q->where('status', '!=', Payment::STATUS_WAIVED)),
                ], 'amount_paid')
                ->orderByDesc('is_online')
                ->latest('last_synced_at')
                ->orderBy('name')
                ->get()
                ->map(fn (AccessPoint $accessPoint) => [
                    'id' => $accessPoint->id,
                    'name' => $accessPoint->name,
                    'mac_address' => $accessPoint->mac_address,
                    'site_name' => $accessPoint->site?->name,
                    'claim_status' => $accessPoint->claim_status,
                    'is_online' => $accessPoint->is_online,
                    'health' => $healthService->present($accessPoint),
                    'active_sessions_count' => $accessPoint->active_sessions_count,
                    'current_sessions_count' => $accessPoint->current_sessions_count,
                    'paid_sessions_count' => $accessPoint->paid_sessions_count,
                    'revenue_total' => number_format((float) ($accessPoint->revenue_total ?? 0), 2, '.', ''),
                    'last_synced_at' => optional($accessPoint->last_synced_at)?->toDateTimeString(),
                ]),
            'healthRuntime' => $healthService->runtimeHealth(),
            'webhookCapabilityVerdict' => $healthService->webhookCapabilityVerdict(),
        ]);
    }
}
