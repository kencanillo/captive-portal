<?php

namespace App\Http\Controllers\Admin;

use App\Mail\OperatorApprovedMail;
use App\Http\Controllers\Controller;
use App\Models\ControllerSetting;
use App\Models\Operator;
use App\Models\PayoutRequest;
use App\Models\Site;
use App\Models\WifiSession;
use App\Services\OperatorPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class OperatorController extends Controller
{
    public function index(OperatorPayoutService $payoutService): Response
    {
        $operators = Operator::query()
            ->with(['user:id,email', 'sites:id,operator_id,name', 'payoutRequests:id,operator_id,status,amount'])
            ->latest()
            ->get();

        return Inertia::render('Admin/Operators/Index', [
            'operators' => $operators->map(function (Operator $operator) use ($payoutService) {
                $summary = $payoutService->summary($operator);

                return [
                    'id' => $operator->id,
                    'business_name' => $operator->business_name,
                    'contact_name' => $operator->contact_name,
                    'email' => $operator->user?->email,
                    'phone_number' => $operator->phone_number,
                    'status' => $operator->status,
                    'requested_site_name' => $operator->requested_site_name,
                    'sites' => $operator->sites->pluck('name')->values()->all(),
                    'available_balance' => number_format((float) $summary['available_balance'], 2, '.', ''),
                    'revenue_total' => number_format((float) $summary['earnings'], 2, '.', ''),
                    'pending_payouts_count' => $operator->payoutRequests->whereIn('status', [
                        PayoutRequest::STATUS_PENDING,
                        PayoutRequest::STATUS_APPROVED,
                        PayoutRequest::STATUS_PROCESSING,
                    ])->count(),
                ];
            }),
        ]);
    }

    public function show(Operator $operator, OperatorPayoutService $payoutService): Response
    {
        $operator->load(['user:id,email', 'sites:id,operator_id,name,slug', 'reviewedBy:id,name']);
        $summary = $payoutService->summary($operator);
        $settings = ControllerSetting::singleton();

        return Inertia::render('Admin/Operators/Show', [
            'operator' => [
                'id' => $operator->id,
                'business_name' => $operator->business_name,
                'contact_name' => $operator->contact_name,
                'email' => $operator->user?->email,
                'phone_number' => $operator->phone_number,
                'status' => $operator->status,
                'requested_site_name' => $operator->requested_site_name,
                'approval_notes' => $operator->approval_notes,
                'reviewed_at' => optional($operator->reviewed_at)?->toDateTimeString(),
                'reviewed_by' => $operator->reviewedBy?->name,
                'payout_preferences' => $operator->payout_preferences,
                'sites' => $operator->sites->map(fn ($site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'slug' => $site->slug,
                ])->all(),
                'summary' => [
                    'revenue_total' => number_format((float) $summary['earnings'], 2, '.', ''),
                    'available_balance' => number_format((float) $summary['available_balance'], 2, '.', ''),
                ],
            ],
            'availableSites' => Site::query()
                ->whereNotNull('omada_site_id')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'operator_id', 'omada_site_id'])
                ->map(fn (Site $site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'slug' => $site->slug,
                    'operator_id' => $site->operator_id,
                    'omada_site_id' => $site->omada_site_id,
                ]),
            'sitesSynced' => $settings->canSyncSites(),
            'recentPayoutRequests' => $operator->payoutRequests()
                ->latest('requested_at')
                ->limit(10)
                ->get()
                ->map(fn (PayoutRequest $payoutRequest) => [
                    'id' => $payoutRequest->id,
                    'amount' => number_format((float) $payoutRequest->amount, 2, '.', ''),
                    'status' => $payoutRequest->status,
                    'requested_at' => optional($payoutRequest->requested_at)?->toDateTimeString(),
                    'processing_mode' => $payoutRequest->processing_mode,
                    'provider' => $payoutRequest->provider,
                ]),
            'recentSessions' => WifiSession::query()
                ->forOperator($operator)
                ->with(['site:id,name', 'plan:id,name'])
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (WifiSession $session) => [
                    'id' => $session->id,
                    'site_name' => $session->site?->name,
                    'plan_name' => $session->plan?->name,
                    'payment_status' => $session->payment_status,
                    'amount_paid' => number_format((float) $session->amount_paid, 2, '.', ''),
                    'updated_at' => optional($session->updated_at)?->toDateTimeString(),
                ]),
        ]);
    }

    public function updateStatus(Request $request, Operator $operator): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,approved,rejected'],
            'approval_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $previousStatus = $operator->status;

        $operator->forceFill([
            'status' => $validated['status'],
            'approval_notes' => $validated['approval_notes'] ?? null,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $request->user()->id,
        ])->save();

        if ($previousStatus !== Operator::STATUS_APPROVED && $operator->status === Operator::STATUS_APPROVED && $operator->user?->email) {
            Mail::to($operator->user->email)->send(new OperatorApprovedMail($operator->loadMissing('user')));
        }

        return redirect()
            ->route('admin.operators.show', $operator)
            ->with('success', 'Operator status updated.');
    }

    public function updateSites(Request $request, Operator $operator): RedirectResponse
    {
        $validated = $request->validate([
            'site_ids' => ['array'],
            'site_ids.*' => ['integer', 'exists:sites,id'],
        ]);

        $selectedSiteIds = collect($validated['site_ids'] ?? [])->unique()->values();

        Site::query()
            ->where('operator_id', $operator->id)
            ->whereNotIn('id', $selectedSiteIds)
            ->update(['operator_id' => null]);

        if ($selectedSiteIds->isNotEmpty()) {
            Site::query()
                ->whereIn('id', $selectedSiteIds)
                ->update(['operator_id' => $operator->id]);
        }

        return redirect()
            ->route('admin.operators.show', $operator)
            ->with('success', 'Operator site assignments updated.');
    }
}
