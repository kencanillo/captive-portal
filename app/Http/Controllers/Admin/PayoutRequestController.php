<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayoutRequest;
use App\Services\OperatorPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PayoutRequestController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/PayoutRequests/Index', [
            'payoutRequests' => PayoutRequest::query()
                ->with(['operator.user:id,email'])
                ->latest('requested_at')
                ->get()
                ->map(fn (PayoutRequest $payoutRequest) => $this->transform($payoutRequest)),
        ]);
    }

    public function approve(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($payoutRequest->status !== PayoutRequest::STATUS_PENDING) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', 'Only pending payout requests can be approved.');
        }

        try {
            $payoutService->approve($payoutRequest, $request->user(), $validated['review_notes'] ?? null);
            $message = 'Payout request approved.';
        } catch (RuntimeException $exception) {
            if (
                config('payouts.mode') === PayoutRequest::MODE_PAYMONGO_TRANSFER
                && config('payouts.providers.paymongo.enabled')
            ) {
                $payoutService->fallbackToManual($payoutRequest, $request->user(), $exception->getMessage());
                $message = 'Automatic payout failed. Request was parked for manual handling.';
            } else {
                return redirect()
                    ->route('admin.payout-requests.index')
                    ->with('error', $exception->getMessage());
            }
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', $message);
    }

    public function reject(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payoutService->reject($payoutRequest, $request->user(), $validated['review_notes'] ?? null);

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout request rejected.');
    }

    public function markProcessing(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payoutService->markProcessing($payoutRequest, $request->user(), $validated['review_notes'] ?? null);

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout request marked as processing.');
    }

    public function markPaid(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payoutService->markPaid($payoutRequest, $request->user(), $validated['review_notes'] ?? null);

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout request marked as paid.');
    }

    public function markFailed(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        $payoutService->markFailed($payoutRequest, $request->user(), $validated['review_notes']);

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout request marked as failed.');
    }

    private function transform(PayoutRequest $payoutRequest): array
    {
        return [
            'id' => $payoutRequest->id,
            'operator_name' => $payoutRequest->operator?->business_name,
            'operator_email' => $payoutRequest->operator?->user?->email,
            'amount' => number_format((float) $payoutRequest->amount, 2, '.', ''),
            'currency' => $payoutRequest->currency,
            'status' => $payoutRequest->status,
            'requested_at' => optional($payoutRequest->requested_at)?->toDateTimeString(),
            'reviewed_at' => optional($payoutRequest->reviewed_at)?->toDateTimeString(),
            'paid_at' => optional($payoutRequest->paid_at)?->toDateTimeString(),
            'notes' => $payoutRequest->notes,
            'review_notes' => $payoutRequest->review_notes,
            'processing_mode' => $payoutRequest->processing_mode,
            'provider' => $payoutRequest->provider,
            'provider_status' => $payoutRequest->provider_status,
            'provider_transfer_reference' => $payoutRequest->provider_transfer_reference,
            'destination_type' => $payoutRequest->destination_type,
            'destination_account_name' => $payoutRequest->destination_account_name,
            'destination_account_reference' => $payoutRequest->destination_account_reference,
            'failure_reason' => $payoutRequest->failure_reason,
        ];
    }
}
