<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayoutRequest;
use App\Services\OperatorAccountingService;
use App\Services\PayoutExecutionOpsService;
use App\Services\OperatorPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class PayoutRequestController extends Controller
{
    public function index(
        OperatorPayoutService $payoutService,
        OperatorAccountingService $operatorAccountingService,
        PayoutExecutionOpsService $payoutExecutionOpsService,
    ): Response
    {
        return Inertia::render('Admin/PayoutRequests/Index', [
            'defaultExecutionProvider' => (string) config('payouts.execution_provider', 'manual'),
            'providerOps' => $payoutExecutionOpsService->runtimeHealth(),
            'payoutRequests' => PayoutRequest::query()
                ->with([
                    'operator.user:id,email',
                    'settlement.settledBy:id,name,email',
                    'settlement.correction.correctedBy:id,name,email',
                    'latestResolution.resolvedBy:id,name,email',
                    'postExecutionHandedOffBy:id,name,email',
                    'latestPostExecutionEvent.eventBy:id,name,email',
                    'executionAttempts.triggeredBy:id,name,email',
                    'executionAttempts.latestResolution.resolvedBy:id,name,email',
                    'latestExecutionAttempt.triggeredBy:id,name,email',
                    'latestExecutionAttempt.latestResolution.resolvedBy:id,name,email',
                ])
                ->latest('requested_at')
                ->get()
                ->map(fn (PayoutRequest $payoutRequest) => $this->transform($payoutRequest, $payoutService, $operatorAccountingService, $payoutExecutionOpsService)),
        ]);
    }

    public function approve(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($payoutRequest->status !== PayoutRequest::STATUS_PENDING_REVIEW) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', 'Only payout requests pending review can be approved.');
        }

        try {
            $payoutService->approve($payoutRequest, $request->user(), $validated['review_notes'] ?? null);
            $message = 'Payout request approved.';
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
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

        try {
            $payoutService->reject($payoutRequest, $request->user(), $validated['review_notes'] ?? null);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout request rejected.');
    }

    public function cancel(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $payoutService->cancel($payoutRequest, $request->user(), $validated['review_notes']);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout request cancelled.');
    }

    public function returnToReview(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $payoutService->returnToReview($payoutRequest, $request->user(), $validated['review_notes']);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout request returned to review.');
    }

    public function settle(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'settlement_reference' => ['nullable', 'string', 'max:255', 'required_without:notes'],
            'notes' => ['nullable', 'string', 'max:2000', 'required_without:settlement_reference'],
        ]);

        try {
            $payoutService->settle($payoutRequest, $request->user(), $validated);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout request settled manually.');
    }

    public function confirmSettlementHandoff(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $payoutService->confirmSettlementHandoff(
                $payoutRequest,
                $request->user(),
                $validated['reason'],
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Completed payout execution handed off for settlement review.');
    }

    public function triggerExecution(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $payoutService->triggerExecution($payoutRequest, $request->user(), $validated['provider'] ?? null);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout execution attempt recorded.');
    }

    public function reverseSettlement(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $payoutService->reverseSettlement(
                $payoutRequest,
                $request->user(),
                $validated['reason'],
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout settlement reversed. The request now requires manual review.');
    }

    public function cancelAndRelease(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $payoutService->cancelAndReleaseReviewRequired(
                $payoutRequest,
                $request->user(),
                $validated['reason'],
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Review-required payout request cancelled and released.');
    }

    public function resolveReturnToReview(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $payoutService->returnReviewRequiredToReview(
                $payoutRequest,
                $request->user(),
                $validated['reason'],
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Review-required payout request returned to review.');
    }

    public function markProcessing(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $payoutService->markProcessing($payoutRequest, $request->user(), $validated['review_notes'] ?? null);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout request marked as processing.');
    }

    public function markPaid(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $payoutService->markPaid($payoutRequest, $request->user(), $validated['review_notes'] ?? null);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout request marked as paid.');
    }

    public function markFailed(Request $request, PayoutRequest $payoutRequest, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $payoutService->markFailed($payoutRequest, $request->user(), $validated['review_notes']);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout request marked as failed.');
    }

    private function transform(
        PayoutRequest $payoutRequest,
        OperatorPayoutService $payoutService,
        OperatorAccountingService $operatorAccountingService,
        PayoutExecutionOpsService $payoutExecutionOpsService,
    ): array
    {
        $summary = $payoutRequest->operator
            ? $payoutService->summary($payoutRequest->operator)
            : null;
        $executionProvider = $payoutRequest->latestExecutionAttempt?->provider_name
            ?: (string) config('payouts.execution_provider', 'manual');
        $executionPreflight = in_array($payoutRequest->status, [
            PayoutRequest::STATUS_PENDING_REVIEW,
            PayoutRequest::STATUS_APPROVED,
            PayoutRequest::STATUS_REVIEW_REQUIRED,
        ], true)
            ? $payoutExecutionOpsService->dispatchPreflight($payoutRequest, $executionProvider)
            : null;
        $retryBudgetRemaining = max(0, $payoutExecutionOpsService->maxRetryAttempts() - max(0, $payoutRequest->executionAttempts->count() - 1));

        return [
            'id' => $payoutRequest->id,
            'operator_name' => $payoutRequest->operator?->business_name,
            'operator_email' => $payoutRequest->operator?->user?->email,
            'amount' => number_format((float) $payoutRequest->amount, 2, '.', ''),
            'currency' => $payoutRequest->currency,
            'status' => $payoutRequest->status,
            'settlement_state' => $payoutRequest->settlement_state,
            'settlement_block_reason' => $payoutRequest->settlement_block_reason,
            'post_execution_state' => $payoutRequest->post_execution_state,
            'post_execution_reason' => $payoutRequest->post_execution_reason,
            'post_execution_updated_at' => optional($payoutRequest->post_execution_updated_at)?->toDateTimeString(),
            'post_execution_handed_off_at' => optional($payoutRequest->post_execution_handed_off_at)?->toDateTimeString(),
            'post_execution_handed_off_by_name' => $payoutRequest->postExecutionHandedOffBy?->name,
            'post_execution_handed_off_by_email' => $payoutRequest->postExecutionHandedOffBy?->email,
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
                'settled_by_name' => $payoutRequest->settlement->settledBy?->name,
                'settled_by_email' => $payoutRequest->settlement->settledBy?->email,
                'correction' => $payoutRequest->settlement->correction ? [
                    'id' => $payoutRequest->settlement->correction->id,
                    'correction_type' => $payoutRequest->settlement->correction->correction_type,
                    'corrected_at' => optional($payoutRequest->settlement->correction->corrected_at)?->toDateTimeString(),
                    'reason' => $payoutRequest->settlement->correction->reason,
                    'notes' => $payoutRequest->settlement->correction->notes,
                    'corrected_by_name' => $payoutRequest->settlement->correction->correctedBy?->name,
                    'corrected_by_email' => $payoutRequest->settlement->correction->correctedBy?->email,
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
                'resolved_by_name' => $payoutRequest->latestResolution->resolvedBy?->name,
                'resolved_by_email' => $payoutRequest->latestResolution->resolvedBy?->email,
            ] : null,
            'latest_execution_attempt' => $payoutRequest->latestExecutionAttempt ? $this->transformExecutionAttempt($payoutRequest->latestExecutionAttempt) : null,
            'latest_post_execution_event' => $payoutRequest->latestPostExecutionEvent ? [
                'id' => $payoutRequest->latestPostExecutionEvent->id,
                'event_type' => $payoutRequest->latestPostExecutionEvent->event_type,
                'event_at' => optional($payoutRequest->latestPostExecutionEvent->event_at)?->toDateTimeString(),
                'reason' => $payoutRequest->latestPostExecutionEvent->reason,
                'notes' => $payoutRequest->latestPostExecutionEvent->notes,
                'resulting_post_execution_state' => $payoutRequest->latestPostExecutionEvent->resulting_post_execution_state,
                'resulting_settlement_state' => $payoutRequest->latestPostExecutionEvent->resulting_settlement_state,
                'event_by_name' => $payoutRequest->latestPostExecutionEvent->eventBy?->name,
                'event_by_email' => $payoutRequest->latestPostExecutionEvent->eventBy?->email,
            ] : null,
            'execution_attempts' => $payoutRequest->executionAttempts
                ->sortByDesc('triggered_at')
                ->values()
                ->map(fn ($attempt) => $this->transformExecutionAttempt($attempt))
                ->all(),
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
            'retry_budget_remaining' => $retryBudgetRemaining,
            'financial_context' => $summary ? [
                'net_payable_fees' => number_format((float) $summary['net_payable_fees'], 2, '.', ''),
                'reserved_for_payout' => number_format((float) $summary['reserved_for_payout'], 2, '.', ''),
                'requestable_balance' => number_format((float) $summary['requestable_balance'], 2, '.', ''),
                'approved_unpaid_reserved' => number_format((float) $summary['approved_unpaid_reserved'], 2, '.', ''),
                'pending_review_reserved' => number_format((float) $summary['pending_review_reserved'], 2, '.', ''),
                'review_required_reserved' => number_format((float) $summary['review_required_reserved'], 2, '.', ''),
                'execution_in_flight_count' => (int) $summary['execution_in_flight_count'],
                'completed_awaiting_settlement_count' => (int) $summary['completed_awaiting_settlement_count'],
                'post_execution_exception_count' => (int) $summary['post_execution_exception_count'],
                'confidence_state' => $summary['confidence_state'],
                'confidence_reasons' => $summary['confidence_reasons'],
                'statement_currency' => $summary['statement_currency'],
            ] : null,
            'statement_preview' => $payoutRequest->operator
                ? $operatorAccountingService->statement($payoutRequest->operator, 3)->all()
                : [],
        ];
    }

    private function transformExecutionAttempt($attempt): array
    {
        return [
            'id' => $attempt->id,
            'parent_attempt_id' => $attempt->parent_attempt_id,
            'execution_state' => $attempt->execution_state,
            'execution_reference' => $attempt->execution_reference,
            'idempotency_key' => $attempt->idempotency_key,
            'external_reference' => $attempt->external_reference,
            'provider_name' => $attempt->provider_name,
            'provider_state' => $attempt->provider_state,
            'provider_state_source' => $attempt->provider_state_source,
            'provider_state_checked_at' => optional($attempt->provider_state_checked_at)?->toDateTimeString(),
            'triggered_at' => optional($attempt->triggered_at)?->toDateTimeString(),
            'last_reconciled_at' => optional($attempt->last_reconciled_at)?->toDateTimeString(),
            'stale_at' => optional($attempt->stale_at)?->toDateTimeString(),
            'completed_at' => optional($attempt->completed_at)?->toDateTimeString(),
            'last_error' => $attempt->last_error,
            'is_stale' => $attempt->isStale(),
            'stale_reason' => $attempt->staleReason(),
            'retry_eligible' => $attempt->isRetryEligible(),
            'can_mark_completed' => $attempt->canBeMarkedCompleted(),
            'can_mark_terminal_failed' => $attempt->canBeMarkedTerminalFailed(),
            'triggered_by_name' => $attempt->triggeredBy?->name,
            'triggered_by_email' => $attempt->triggeredBy?->email,
            'latest_resolution' => $attempt->latestResolution ? [
                'id' => $attempt->latestResolution->id,
                'resolution_type' => $attempt->latestResolution->resolution_type,
                'resolved_at' => optional($attempt->latestResolution->resolved_at)?->toDateTimeString(),
                'reason' => $attempt->latestResolution->reason,
                'notes' => $attempt->latestResolution->notes,
                'resulting_state' => $attempt->latestResolution->resulting_state,
                'resolved_by_name' => $attempt->latestResolution->resolvedBy?->name,
                'resolved_by_email' => $attempt->latestResolution->resolvedBy?->email,
            ] : null,
        ];
    }
}
