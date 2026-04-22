<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOperatorPayoutRequest;
use App\Models\PayoutRequest;
use App\Services\OperatorAccountingService;
use App\Services\OperatorPayoutService;
use App\Services\PayoutExecutionOpsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PayoutController extends Controller
{
    public function index(
        OperatorPayoutService $payoutService,
        OperatorAccountingService $operatorAccountingService,
        PayoutExecutionOpsService $payoutExecutionOpsService,
    ): Response
    {
        $operator = request()->user()->loadMissing('operator')->operator;

        return Inertia::render('Operator/Payouts', [
            'summary' => $this->formatSummary($payoutService->summary($operator)),
            'providerOps' => $payoutExecutionOpsService->runtimeHealth(),
            'statementLines' => $operatorAccountingService->statement($operator, 10)->all(),
            'pendingRequests' => PayoutRequest::query()
                ->forOperator($operator)
                ->with(['settlement.correction', 'latestResolution', 'latestExecutionAttempt.latestResolution', 'latestPostExecutionEvent'])
                ->whereIn('status', [
                    PayoutRequest::STATUS_PENDING_REVIEW,
                    PayoutRequest::STATUS_APPROVED,
                    PayoutRequest::STATUS_REVIEW_REQUIRED,
                    PayoutRequest::STATUS_PROCESSING,
                ])
                ->latest('requested_at')
                ->get()
                ->map(fn (PayoutRequest $payoutRequest) => $this->transformPayoutRequest($payoutRequest, $payoutExecutionOpsService)),
            'completedRequests' => PayoutRequest::query()
                ->forOperator($operator)
                ->with(['settlement.correction', 'latestResolution', 'latestExecutionAttempt.latestResolution', 'latestPostExecutionEvent'])
                ->whereIn('status', [
                    PayoutRequest::STATUS_SETTLED,
                    PayoutRequest::STATUS_PAID,
                    PayoutRequest::STATUS_REJECTED,
                    PayoutRequest::STATUS_CANCELLED,
                    PayoutRequest::STATUS_FAILED,
                ])
                ->latest('requested_at')
                ->get()
                ->map(fn (PayoutRequest $payoutRequest) => $this->transformPayoutRequest($payoutRequest, $payoutExecutionOpsService)),
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
            ->map(function ($value) {
                if (is_numeric($value)) {
                    return number_format((float) $value, 2, '.', '');
                }

                return $value;
            })
            ->all();
    }

    private function transformPayoutRequest(PayoutRequest $payoutRequest, PayoutExecutionOpsService $payoutExecutionOpsService): array
    {
        $executionProvider = $payoutRequest->latestExecutionAttempt?->provider_name
            ?: (string) config('payouts.execution_provider', 'manual');
        $executionPreflight = in_array($payoutRequest->status, [
            PayoutRequest::STATUS_PENDING_REVIEW,
            PayoutRequest::STATUS_APPROVED,
            PayoutRequest::STATUS_REVIEW_REQUIRED,
        ], true)
            ? $payoutExecutionOpsService->dispatchPreflight($payoutRequest, $executionProvider)
            : null;

        return [
            'id' => $payoutRequest->id,
            'amount' => number_format((float) $payoutRequest->amount, 2, '.', ''),
            'currency' => $payoutRequest->currency,
            'status' => $payoutRequest->status,
            'settlement_state' => $payoutRequest->settlement_state,
            'settlement_block_reason' => $payoutRequest->settlement_block_reason,
            'post_execution_state' => $payoutRequest->post_execution_state,
            'post_execution_reason' => $payoutRequest->post_execution_reason,
            'post_execution_updated_at' => optional($payoutRequest->post_execution_updated_at)?->toDateTimeString(),
            'post_execution_handed_off_at' => optional($payoutRequest->post_execution_handed_off_at)?->toDateTimeString(),
            'requested_at' => optional($payoutRequest->requested_at)?->toDateTimeString(),
            'reviewed_at' => optional($payoutRequest->reviewed_at)?->toDateTimeString(),
            'cancelled_at' => optional($payoutRequest->cancelled_at)?->toDateTimeString(),
            'invalidated_at' => optional($payoutRequest->invalidated_at)?->toDateTimeString(),
            'settlement_checked_at' => optional($payoutRequest->settlement_checked_at)?->toDateTimeString(),
            'settlement_ready_at' => optional($payoutRequest->settlement_ready_at)?->toDateTimeString(),
            'paid_at' => optional($payoutRequest->paid_at)?->toDateTimeString(),
            'settlement' => $payoutRequest->settlement ? [
                'id' => $payoutRequest->settlement->id,
                'amount' => number_format((float) $payoutRequest->settlement->amount, 2, '.', ''),
                'currency' => $payoutRequest->settlement->currency,
                'settled_at' => optional($payoutRequest->settlement->settled_at)?->toDateTimeString(),
                'settlement_reference' => $payoutRequest->settlement->settlement_reference,
                'notes' => $payoutRequest->settlement->notes,
                'correction' => $payoutRequest->settlement->correction ? [
                    'id' => $payoutRequest->settlement->correction->id,
                    'correction_type' => $payoutRequest->settlement->correction->correction_type,
                    'corrected_at' => optional($payoutRequest->settlement->correction->corrected_at)?->toDateTimeString(),
                    'reason' => $payoutRequest->settlement->correction->reason,
                    'notes' => $payoutRequest->settlement->correction->notes,
                ] : null,
            ] : null,
            'latest_resolution' => $payoutRequest->latestResolution ? [
                'id' => $payoutRequest->latestResolution->id,
                'resolution_type' => $payoutRequest->latestResolution->resolution_type,
                'resolved_at' => optional($payoutRequest->latestResolution->resolved_at)?->toDateTimeString(),
                'reason' => $payoutRequest->latestResolution->reason,
                'notes' => $payoutRequest->latestResolution->notes,
                'resulting_status' => $payoutRequest->latestResolution->resulting_status,
                'resulting_settlement_state' => $payoutRequest->latestResolution->resulting_settlement_state,
            ] : null,
            'latest_execution_attempt' => $payoutRequest->latestExecutionAttempt ? [
                'id' => $payoutRequest->latestExecutionAttempt->id,
                'parent_attempt_id' => $payoutRequest->latestExecutionAttempt->parent_attempt_id,
                'execution_state' => $payoutRequest->latestExecutionAttempt->execution_state,
                'execution_reference' => $payoutRequest->latestExecutionAttempt->execution_reference,
                'external_reference' => $payoutRequest->latestExecutionAttempt->external_reference,
                'provider_name' => $payoutRequest->latestExecutionAttempt->provider_name,
                'provider_state' => $payoutRequest->latestExecutionAttempt->provider_state,
                'provider_state_source' => $payoutRequest->latestExecutionAttempt->provider_state_source,
                'provider_state_checked_at' => optional($payoutRequest->latestExecutionAttempt->provider_state_checked_at)?->toDateTimeString(),
                'triggered_at' => optional($payoutRequest->latestExecutionAttempt->triggered_at)?->toDateTimeString(),
                'last_reconciled_at' => optional($payoutRequest->latestExecutionAttempt->last_reconciled_at)?->toDateTimeString(),
                'stale_at' => optional($payoutRequest->latestExecutionAttempt->stale_at)?->toDateTimeString(),
                'completed_at' => optional($payoutRequest->latestExecutionAttempt->completed_at)?->toDateTimeString(),
                'last_error' => $payoutRequest->latestExecutionAttempt->last_error,
                'is_stale' => $payoutRequest->latestExecutionAttempt->isStale(),
                'stale_reason' => $payoutRequest->latestExecutionAttempt->staleReason(),
                'retry_eligible' => $payoutRequest->latestExecutionAttempt->isRetryEligible(),
                'latest_resolution' => $payoutRequest->latestExecutionAttempt->latestResolution ? [
                    'id' => $payoutRequest->latestExecutionAttempt->latestResolution->id,
                    'resolution_type' => $payoutRequest->latestExecutionAttempt->latestResolution->resolution_type,
                    'resolved_at' => optional($payoutRequest->latestExecutionAttempt->latestResolution->resolved_at)?->toDateTimeString(),
                    'reason' => $payoutRequest->latestExecutionAttempt->latestResolution->reason,
                    'notes' => $payoutRequest->latestExecutionAttempt->latestResolution->notes,
                    'resulting_state' => $payoutRequest->latestExecutionAttempt->latestResolution->resulting_state,
                ] : null,
            ] : null,
            'latest_post_execution_event' => $payoutRequest->latestPostExecutionEvent ? [
                'id' => $payoutRequest->latestPostExecutionEvent->id,
                'event_type' => $payoutRequest->latestPostExecutionEvent->event_type,
                'event_at' => optional($payoutRequest->latestPostExecutionEvent->event_at)?->toDateTimeString(),
                'reason' => $payoutRequest->latestPostExecutionEvent->reason,
                'notes' => $payoutRequest->latestPostExecutionEvent->notes,
                'resulting_post_execution_state' => $payoutRequest->latestPostExecutionEvent->resulting_post_execution_state,
                'resulting_settlement_state' => $payoutRequest->latestPostExecutionEvent->resulting_settlement_state,
            ] : null,
            'notes' => $payoutRequest->notes,
            'review_notes' => $payoutRequest->review_notes,
            'cancellation_reason' => $payoutRequest->cancellation_reason,
            'processing_mode' => $payoutRequest->processing_mode,
            'provider' => $payoutRequest->provider,
            'provider_status' => $payoutRequest->provider_status,
            'provider_transfer_reference' => $payoutRequest->provider_transfer_reference,
            'destination_type' => $payoutRequest->destination_type,
            'destination_account_name' => $payoutRequest->destination_account_name,
            'destination_account_reference' => $payoutRequest->destination_account_reference,
            'failure_reason' => $payoutRequest->failure_reason,
            'execution_preflight' => $executionPreflight,
        ];
    }
}
