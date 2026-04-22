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
                ->with(['client:id,name,phone_number', 'plan:id,name', 'site:id,name'])
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn (WifiSession $session) => [
                    'id' => $session->id,
                    'client_name' => $session->client?->name,
                    'plan_name' => $session->plan?->name,
                    'site_name' => $session->site?->name,
                    'payment_status' => $session->payment_status,
                    'session_status' => $session->session_status,
                    'amount_paid' => number_format((float) $session->amount_paid, 2, '.', ''),
                    'updated_at' => optional($session->updated_at)?->toDateTimeString(),
                ]),
            'recentPayments' => Payment::query()
                ->forOperator($operator)
                ->with(['wifiSession.plan:id,name', 'wifiSession.site:id,name'])
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'amount' => number_format((float) $payment->amount, 2, '.', ''),
                    'plan_name' => $payment->wifiSession?->plan?->name,
                    'site_name' => $payment->wifiSession?->site?->name,
                    'reference_id' => $payment->reference_id,
                    'updated_at' => optional($payment->updated_at)?->toDateTimeString(),
                ]),
            'recentAccessPoints' => AccessPoint::query()
                ->forOperator($operator)
                ->with('site:id,name')
                ->latest('last_synced_at')
                ->limit(6)
                ->get()
                ->map(fn (AccessPoint $accessPoint) => [
                    'id' => $accessPoint->id,
                    'name' => $accessPoint->name,
                    'site_name' => $accessPoint->site?->name,
                    'claim_status' => $accessPoint->claim_status,
                    'health' => $healthService->present($accessPoint),
                    'last_synced_at' => optional($accessPoint->last_synced_at)?->toDateTimeString(),
                ]),
            'healthRuntime' => $healthService->runtimeHealth(),
            'webhookCapabilityVerdict' => $healthService->webhookCapabilityVerdict(),
        ]);
    }
}
