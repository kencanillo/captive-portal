<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOperatorPayoutRequest;
use App\Models\PayoutRequest;
use App\Services\OperatorPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PayoutController extends Controller
{
    public function index(OperatorPayoutService $payoutService): Response
    {
        $operator = request()->user()->loadMissing('operator')->operator;

        return Inertia::render('Operator/Payouts', [
            'summary' => $this->formatSummary($payoutService->summary($operator)),
            'pendingRequests' => PayoutRequest::query()
                ->forOperator($operator)
                ->whereIn('status', [
                    PayoutRequest::STATUS_PENDING,
                    PayoutRequest::STATUS_APPROVED,
                    PayoutRequest::STATUS_PROCESSING,
                ])
                ->latest('requested_at')
                ->get()
                ->map(fn (PayoutRequest $payoutRequest) => $this->transformPayoutRequest($payoutRequest)),
            'completedRequests' => PayoutRequest::query()
                ->forOperator($operator)
                ->whereIn('status', [
                    PayoutRequest::STATUS_PAID,
                    PayoutRequest::STATUS_REJECTED,
                    PayoutRequest::STATUS_FAILED,
                ])
                ->latest('requested_at')
                ->get()
                ->map(fn (PayoutRequest $payoutRequest) => $this->transformPayoutRequest($payoutRequest)),
        ]);
    }

    public function store(StoreOperatorPayoutRequest $request, OperatorPayoutService $payoutService): RedirectResponse
    {
        $operator = $request->user()->loadMissing('operator')->operator;

        try {
            $payoutService->createRequest($operator, $request->validated());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'amount' => [$exception->getMessage()],
            ]);
        }

        return redirect()
            ->route('operator.payouts.index')
            ->with('success', 'Payout request submitted for admin review.');
    }

    private function formatSummary(array $summary): array
    {
        return collect($summary)
            ->map(fn ($value) => number_format((float) $value, 2, '.', ''))
            ->all();
    }

    private function transformPayoutRequest(PayoutRequest $payoutRequest): array
    {
        return [
            'id' => $payoutRequest->id,
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
