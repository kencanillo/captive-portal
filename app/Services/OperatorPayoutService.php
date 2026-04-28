<?php

namespace App\Services;

use App\Models\AccessPoint;
use App\Models\BillingLedgerEntry;
use App\Models\Operator;
use App\Models\PayoutExecutionAttempt;
use App\Models\PayoutExecutionAttemptResolution;
use App\Models\PayoutPostExecutionEvent;
use App\Models\PayoutRequest;
use App\Models\PayoutRequestResolution;
use App\Models\PayoutSettlement;
use App\Models\PayoutSettlementCorrection;
use App\Services\PayoutExecutions\PayoutExecutionAdapterFactory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OperatorPayoutService
{
    public function __construct(
        private readonly OperatorAccountingService $operatorAccountingService,
        private readonly OperationalReadinessService $operationalReadinessService,
        private readonly PayoutExecutionAdapterFactory $payoutExecutionAdapterFactory,
        private readonly PayoutExecutionOpsService $payoutExecutionOpsService,
    ) {
    }

    public function summary(Operator $operator): array
    {
        $this->syncSettlementStatesForOperator($operator->id);

        return $this->buildSummary($operator->id);
    }

    public function createRequest(Operator $operator, array $attributes): PayoutRequest
    {
        $amount = round((float) $attributes['amount'], 2);

        if ($amount <= 0) {
            throw new RuntimeException('Payout amount must be greater than zero.');
        }

        return DB::transaction(function () use ($operator, $attributes, $amount): PayoutRequest {
            $lockedOperator = $this->lockOperatorBalanceContext($operator->id);
            $this->syncSettlementStatesForOperator($lockedOperator->id);
            $summary = $this->buildSummary($lockedOperator->id);

            if ((float) ($summary['gross_sales'] ?? 0) <= 0.0) {
                $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_REQUEST_CREATE);
            }

            $this->assertBalanceConfidenceHealthy($summary, 'Payout request creation is blocked because the operator balance is not trustworthy enough yet.');

            if ($amount > $summary['requestable_balance']) {
                throw new RuntimeException('Payout amount exceeds the requestable balance.');
            }

            return $lockedOperator->payoutRequests()->create([
                'amount' => $amount,
                'currency' => (string) config('payouts.currency', 'PHP'),
                'status' => PayoutRequest::STATUS_PENDING_REVIEW,
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_NOT_READY,
                'requested_at' => now(),
                'notes' => $attributes['notes'] ?? null,
                'destination_type' => $attributes['destination_type'],
                'destination_account_name' => $attributes['destination_account_name'],
                'destination_account_reference' => $attributes['destination_account_reference'],
                'destination_snapshot' => [
                    'type' => $attributes['destination_type'],
                    'account_name' => $attributes['destination_account_name'],
                    'account_reference' => $attributes['destination_account_reference'],
                    'provider' => $attributes['destination_provider'] ?? null,
                    'bic' => $attributes['destination_bic'] ?? null,
                    'notes' => $attributes['destination_notes'] ?? null,
                ],
                'metadata' => [
                    'request_balance_snapshot' => $this->balanceSnapshot($summary),
                ],
            ]);
        });
    }

    public function triggerExecution(PayoutRequest $payoutRequest, User $reviewer, ?string $provider = null): PayoutExecutionAttempt
    {
        return DB::transaction(function () use ($payoutRequest, $reviewer, $provider): PayoutExecutionAttempt {
            $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_EXECUTION);

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->with(['settlement.correction', 'latestExecutionAttempt.latestResolution'])
                ->lockForUpdate()
                ->findOrFail($payoutRequest->id);

            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $this->syncSettlementStatesForOperator($lockedRequest->operator_id);
            $lockedRequest->refresh()->load(['settlement.correction', 'latestExecutionAttempt.latestResolution']);

            return $this->createAndDispatchExecutionAttempt(
                $lockedRequest,
                $reviewer,
                $provider,
                null,
                'execution_attempt_triggered',
            );
        });
    }

    public function reconcileExecutionAttempt(PayoutExecutionAttempt $attempt, User $reviewer, ?array $providerPayload = null): PayoutExecutionAttempt
    {
        return DB::transaction(function () use ($attempt, $reviewer, $providerPayload): PayoutExecutionAttempt {
            $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_EXECUTION);

            /** @var PayoutExecutionAttempt $lockedAttempt */
            $lockedAttempt = PayoutExecutionAttempt::query()
                ->with(['payoutRequest.settlement.correction', 'latestResolution'])
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            $this->assertExecutionAttemptCanBeReconciled($lockedAttempt);

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->with('settlement.correction')
                ->lockForUpdate()
                ->findOrFail($lockedAttempt->payout_request_id);

            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $summary = $this->buildSummary($lockedRequest->operator_id, $lockedRequest->id);
            $this->assertBalanceConfidenceHealthy($summary, 'Execution reconciliation is blocked because the operator balance is not trustworthy enough yet.');

            $adapter = $this->payoutExecutionAdapterFactory->make($lockedAttempt->provider_name);
            $result = $adapter->reconcile($lockedRequest, $lockedAttempt, $providerPayload, 'poll');

            return $this->applyExecutionProviderUpdate(
                $lockedAttempt,
                $lockedRequest,
                $result,
                'poll',
                $providerPayload,
                [
                    'reviewer' => $reviewer,
                    'create_stale_resolution' => true,
                    'event_type' => 'execution_attempt_reconciled',
                ],
            );
        });
    }

    public function reconcileExecutionAttemptInBackground(PayoutExecutionAttempt $attempt, ?array $providerPayload = null): PayoutExecutionAttempt
    {
        return DB::transaction(function () use ($attempt, $providerPayload): PayoutExecutionAttempt {
            /** @var PayoutExecutionAttempt $lockedAttempt */
            $lockedAttempt = PayoutExecutionAttempt::query()
                ->with(['payoutRequest.settlement.correction', 'latestResolution'])
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            $this->assertExecutionAttemptCanBeReconciled($lockedAttempt);

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->with(['settlement.correction', 'latestExecutionAttempt'])
                ->lockForUpdate()
                ->findOrFail($lockedAttempt->payout_request_id);

            $latestAttempt = $lockedRequest->latestExecutionAttempt;

            if (! $latestAttempt || $latestAttempt->id !== $lockedAttempt->id) {
                return $lockedAttempt->refresh()->load('latestResolution.resolvedBy');
            }

            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $adapter = $this->payoutExecutionAdapterFactory->make($lockedAttempt->provider_name);
            $result = $adapter->reconcile($lockedRequest, $lockedAttempt, $providerPayload, 'poll');

            return $this->applyExecutionProviderUpdate(
                $lockedAttempt,
                $lockedRequest,
                $result,
                'poll',
                $providerPayload,
                [
                    'create_stale_resolution' => true,
                    'event_type' => 'execution_attempt_reconciled_in_background',
                ],
            );
        });
    }

    public function handleExecutionProviderCallback(PayoutExecutionAttempt $attempt, string $payload, ?string $signatureHeader): PayoutExecutionAttempt
    {
        return DB::transaction(function () use ($attempt, $payload, $signatureHeader): PayoutExecutionAttempt {
            /** @var PayoutExecutionAttempt $lockedAttempt */
            $lockedAttempt = PayoutExecutionAttempt::query()
                ->with('payoutRequest.settlement.correction')
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->with('settlement.correction')
                ->lockForUpdate()
                ->findOrFail($lockedAttempt->payout_request_id);

            $adapter = $this->payoutExecutionAdapterFactory->make($lockedAttempt->provider_name ?: config('payouts.execution_provider', 'manual'));

            if (! $adapter->verifyCallback($payload, $signatureHeader)) {
                throw new RuntimeException('Invalid payout execution callback signature.');
            }

            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $callbackExternalReference = data_get($decoded, 'data.id');

            if ($lockedAttempt->external_reference && $callbackExternalReference && $lockedAttempt->external_reference !== $callbackExternalReference) {
                throw new RuntimeException('Callback payload does not match the expected payout execution attempt.');
            }

            $result = $adapter->reconcile($lockedRequest, $lockedAttempt, $decoded, 'callback');

            return $this->applyExecutionProviderUpdate(
                $lockedAttempt,
                $lockedRequest,
                $result,
                'callback',
                $decoded,
                [
                    'event_type' => 'execution_attempt_provider_callback',
                ],
            );
        });
    }

    public function markExecutionCompleted(PayoutExecutionAttempt $attempt, User $reviewer, string $reason, ?string $notes = null): PayoutExecutionAttempt
    {
        $reason = trim($reason);
        $notes = $notes !== null ? trim($notes) : null;

        if ($reason === '') {
            throw new RuntimeException('Execution completion reason is required.');
        }

        return DB::transaction(function () use ($attempt, $reviewer, $reason, $notes): PayoutExecutionAttempt {
            $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_EXECUTION);

            /** @var PayoutExecutionAttempt $lockedAttempt */
            $lockedAttempt = PayoutExecutionAttempt::query()
                ->with('payoutRequest')
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            if (! $lockedAttempt->canBeMarkedCompleted()) {
                throw new RuntimeException('Only unresolved execution attempts can be marked completed.');
            }

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()->lockForUpdate()->findOrFail($lockedAttempt->payout_request_id);
            $this->assertPayoutRequestSupportsExecutionLifecycle($lockedRequest);
            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $summary = $this->buildSummary($lockedRequest->operator_id, $lockedRequest->id);
            $this->assertBalanceConfidenceHealthy($summary, 'Execution completion is blocked because the operator balance is not trustworthy enough yet.');

            $completedAt = now();

            $lockedAttempt->forceFill([
                'execution_state' => PayoutExecutionAttempt::STATE_COMPLETED,
                'last_error' => null,
                'completed_at' => $completedAt,
                'last_reconciled_at' => $completedAt,
            ])->save();

            $this->recordExecutionResolution(
                $lockedAttempt,
                $lockedRequest,
                $reviewer,
                PayoutExecutionAttemptResolution::TYPE_MARKED_COMPLETED,
                $reason,
                $notes,
                PayoutExecutionAttempt::STATE_COMPLETED,
                [
                    'balance_snapshot' => $this->balanceSnapshot($summary),
                ],
            );

            $lockedRequest->forceFill([
                'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                    'type' => 'execution_attempt_marked_completed',
                    'at' => $completedAt->toIso8601String(),
                    'by_user_id' => $reviewer->id,
                    'execution_reference' => $lockedAttempt->execution_reference,
                    'reason' => $reason,
                    'notes' => $notes,
                ]),
            ])->save();

            return $lockedAttempt->refresh()->load('latestResolution.resolvedBy');
        });
    }

    public function markExecutionTerminalFailed(PayoutExecutionAttempt $attempt, User $reviewer, string $reason, ?string $notes = null): PayoutExecutionAttempt
    {
        $reason = trim($reason);
        $notes = $notes !== null ? trim($notes) : null;

        if ($reason === '') {
            throw new RuntimeException('Execution failure reason is required.');
        }

        return DB::transaction(function () use ($attempt, $reviewer, $reason, $notes): PayoutExecutionAttempt {
            $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_EXECUTION);

            /** @var PayoutExecutionAttempt $lockedAttempt */
            $lockedAttempt = PayoutExecutionAttempt::query()
                ->with('payoutRequest')
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            if (! $lockedAttempt->canBeMarkedTerminalFailed()) {
                throw new RuntimeException('Only unresolved or retryable execution attempts can be marked terminal failed.');
            }

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()->lockForUpdate()->findOrFail($lockedAttempt->payout_request_id);
            $this->assertPayoutRequestSupportsExecutionLifecycle($lockedRequest);
            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $summary = $this->buildSummary($lockedRequest->operator_id, $lockedRequest->id);
            $this->assertBalanceConfidenceHealthy($summary, 'Execution failure resolution is blocked because the operator balance is not trustworthy enough yet.');

            $failedAt = now();

            $lockedAttempt->forceFill([
                'execution_state' => PayoutExecutionAttempt::STATE_TERMINAL_FAILED,
                'last_error' => $reason,
                'completed_at' => $failedAt,
                'last_reconciled_at' => $failedAt,
            ])->save();

            $this->recordExecutionResolution(
                $lockedAttempt,
                $lockedRequest,
                $reviewer,
                PayoutExecutionAttemptResolution::TYPE_MARKED_TERMINAL_FAILED,
                $reason,
                $notes,
                PayoutExecutionAttempt::STATE_TERMINAL_FAILED,
                [
                    'balance_snapshot' => $this->balanceSnapshot($summary),
                ],
            );

            $lockedRequest->forceFill([
                'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                    'type' => 'execution_attempt_marked_terminal_failed',
                    'at' => $failedAt->toIso8601String(),
                    'by_user_id' => $reviewer->id,
                    'execution_reference' => $lockedAttempt->execution_reference,
                    'reason' => $reason,
                    'notes' => $notes,
                ]),
            ])->save();

            return $lockedAttempt->refresh()->load('latestResolution.resolvedBy');
        });
    }

    public function retryExecutionAttempt(PayoutExecutionAttempt $attempt, User $reviewer, string $reason, ?string $notes = null, ?string $provider = null): PayoutExecutionAttempt
    {
        $reason = trim($reason);
        $notes = $notes !== null ? trim($notes) : null;

        if ($reason === '') {
            throw new RuntimeException('Execution retry reason is required.');
        }

        return DB::transaction(function () use ($attempt, $reviewer, $reason, $notes, $provider): PayoutExecutionAttempt {
            $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_EXECUTION);

            /** @var PayoutExecutionAttempt $lockedAttempt */
            $lockedAttempt = PayoutExecutionAttempt::query()
                ->with(['payoutRequest.settlement.correction', 'latestResolution'])
                ->lockForUpdate()
                ->findOrFail($attempt->id);

            if (! $lockedAttempt->isRetryEligible()) {
                throw new RuntimeException('Only retryable failed execution attempts can be retried.');
            }

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->with(['settlement.correction', 'latestExecutionAttempt.latestResolution'])
                ->lockForUpdate()
                ->findOrFail($lockedAttempt->payout_request_id);

            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $this->syncSettlementStatesForOperator($lockedRequest->operator_id);
            $lockedRequest->refresh()->load(['settlement.correction', 'latestExecutionAttempt.latestResolution']);

            $latestAttempt = $lockedRequest->latestExecutionAttempt;

            if (! $latestAttempt || $latestAttempt->id !== $lockedAttempt->id) {
                throw new RuntimeException('Only the latest execution attempt can be retried.');
            }

            $this->assertRetryBudgetAvailable($lockedRequest);

            $this->recordExecutionResolution(
                $lockedAttempt,
                $lockedRequest,
                $reviewer,
                PayoutExecutionAttemptResolution::TYPE_RETRIED,
                $reason,
                $notes,
                $lockedAttempt->execution_state,
                [
                    'triggering_attempt_id' => $lockedAttempt->id,
                ],
            );

            return $this->createAndDispatchExecutionAttempt(
                $lockedRequest,
                $reviewer,
                $provider,
                $lockedAttempt,
                'execution_attempt_retried',
            );
        });
    }

    public function settle(PayoutRequest $payoutRequest, User $reviewer, array $attributes): PayoutRequest
    {
        $amount = round((float) $attributes['amount'], 2);

        return DB::transaction(function () use ($payoutRequest, $reviewer, $attributes, $amount): PayoutRequest {
            $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_SETTLEMENT);

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->with(['settlement', 'latestExecutionAttempt'])
                ->lockForUpdate()
                ->findOrFail($payoutRequest->id);

            if ($lockedRequest->status !== PayoutRequest::STATUS_APPROVED) {
                throw new RuntimeException('Only approved payout requests can be settled.');
            }

            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $this->syncSettlementStatesForOperator($lockedRequest->operator_id);
            $lockedRequest->refresh();
            $lockedRequest->loadMissing(['settlement', 'latestExecutionAttempt']);

            if ($lockedRequest->status !== PayoutRequest::STATUS_APPROVED
                || $lockedRequest->settlement_state !== PayoutRequest::SETTLEMENT_STATE_READY) {
                throw new RuntimeException('Only approved payout requests with settlement state ready can be manually settled.');
            }

            if ($lockedRequest->settlement()->exists()) {
                throw new RuntimeException('This payout request already has a recorded settlement.');
            }

            if ($lockedRequest->latestExecutionAttempt
                && $lockedRequest->latestExecutionAttempt->execution_state !== PayoutExecutionAttempt::STATE_COMPLETED) {
                throw new RuntimeException('Manual settlement is blocked because the latest payout execution attempt is not resolved as completed yet.');
            }

            if (
                $lockedRequest->latestExecutionAttempt
                && $lockedRequest->latestExecutionAttempt->execution_state === PayoutExecutionAttempt::STATE_COMPLETED
                && $lockedRequest->post_execution_handed_off_at === null
            ) {
                throw new RuntimeException('Manual settlement is blocked until the completed payout execution is explicitly handed off for settlement review.');
            }

            if ($amount <= 0) {
                throw new RuntimeException('Settlement amount must be greater than zero.');
            }

            if ($amount !== round((float) $lockedRequest->amount, 2)) {
                throw new RuntimeException('This phase only supports exact full settlement. Partial settlement is not allowed.');
            }

            $reference = trim((string) ($attributes['settlement_reference'] ?? ''));
            $notes = trim((string) ($attributes['notes'] ?? ''));

            if ($reference === '' && $notes === '') {
                throw new RuntimeException('Settlement reference or notes are required.');
            }

            $summary = $this->buildSummary($lockedRequest->operator_id, $lockedRequest->id);
            $this->assertBalanceConfidenceHealthy($summary, 'Manual settlement is blocked because the operator balance is not trustworthy enough yet.');

            if ((float) $lockedRequest->amount > (float) $summary['requestable_balance']) {
                throw new RuntimeException('Manual settlement is blocked because the approved request is no longer fully supported by current payable balance.');
            }

            $settledAt = now();

            PayoutSettlement::query()->create([
                'payout_request_id' => $lockedRequest->id,
                'operator_id' => $lockedRequest->operator_id,
                'amount' => $amount,
                'currency' => $lockedRequest->currency,
                'settled_at' => $settledAt,
                'settled_by_user_id' => $reviewer->id,
                'settlement_reference' => $reference !== '' ? $reference : null,
                'notes' => $notes !== '' ? $notes : null,
                'metadata' => [
                    'request_balance_snapshot' => $this->balanceSnapshot($summary),
                    'destination_snapshot' => $lockedRequest->destination_snapshot,
                ],
            ]);

            $lockedRequest->forceFill([
                'status' => PayoutRequest::STATUS_SETTLED,
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_SETTLED,
                'settlement_block_reason' => null,
                'settlement_checked_at' => $settledAt,
                'settlement_ready_at' => $lockedRequest->settlement_ready_at ?? $settledAt,
                'invalidated_at' => null,
                'invalidated_by_user_id' => null,
                'provider_status' => 'manually_settled',
                'paid_at' => $settledAt,
                'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                    'type' => 'settled_manually',
                    'at' => $settledAt->toIso8601String(),
                    'by_user_id' => $reviewer->id,
                    'settlement_reference' => $reference !== '' ? $reference : null,
                    'notes' => $notes !== '' ? $notes : null,
                    'balance_snapshot' => $this->balanceSnapshot($summary),
                ]),
            ])->save();

            return $lockedRequest->refresh()->load('settlement');
        });
    }

    public function reverseSettlement(PayoutRequest $payoutRequest, User $reviewer, string $reason, ?string $notes = null): PayoutRequest
    {
        $reason = trim($reason);
        $notes = $notes !== null ? trim($notes) : null;

        if ($reason === '') {
            throw new RuntimeException('Settlement reversal reason is required.');
        }

        return DB::transaction(function () use ($payoutRequest, $reviewer, $reason, $notes): PayoutRequest {
            $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_SETTLEMENT);

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->with(['settlement.correction'])
                ->lockForUpdate()
                ->findOrFail($payoutRequest->id);

            if (! in_array($lockedRequest->status, PayoutRequest::SETTLED_STATUSES, true)) {
                throw new RuntimeException('Only settled payout requests can have their settlement reversed.');
            }

            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $lockedRequest->refresh();
            $lockedRequest->load(['settlement.correction']);

            $settlement = $lockedRequest->settlement;

            if (! $settlement) {
                throw new RuntimeException('This payout request has no settlement record to reverse.');
            }

            if ($settlement->correction) {
                throw new RuntimeException('This payout settlement already has a recorded correction.');
            }

            $summary = $this->buildSummary($lockedRequest->operator_id, $lockedRequest->id);
            $this->assertBalanceConfidenceHealthy($summary, 'Settlement reversal is blocked because the operator balance is not trustworthy enough yet.');

            $correctedAt = now();

            PayoutSettlementCorrection::query()->create([
                'payout_settlement_id' => $settlement->id,
                'payout_request_id' => $lockedRequest->id,
                'operator_id' => $lockedRequest->operator_id,
                'correction_type' => PayoutSettlementCorrection::TYPE_REVERSAL,
                'corrected_at' => $correctedAt,
                'corrected_by_user_id' => $reviewer->id,
                'reason' => $reason,
                'notes' => $notes !== '' ? $notes : null,
                'metadata' => [
                    'settlement_snapshot' => [
                        'amount' => round((float) $settlement->amount, 2),
                        'currency' => $settlement->currency,
                        'settled_at' => optional($settlement->settled_at)?->toIso8601String(),
                        'settlement_reference' => $settlement->settlement_reference,
                    ],
                    'request_balance_snapshot' => $this->balanceSnapshot($summary),
                ],
            ]);

            $lockedRequest->forceFill([
                'status' => PayoutRequest::STATUS_REVIEW_REQUIRED,
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_REVERSED,
                'settlement_block_reason' => PayoutRequest::SETTLEMENT_BLOCK_REVERSED,
                'settlement_checked_at' => $correctedAt,
                'settlement_ready_at' => null,
                'invalidated_at' => $lockedRequest->invalidated_at ?? $correctedAt,
                'invalidated_by_user_id' => $reviewer->id,
                'provider_status' => 'settlement_reversed_manual_review',
                'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                    'type' => 'settlement_reversed',
                    'at' => $correctedAt->toIso8601String(),
                    'by_user_id' => $reviewer->id,
                    'reason' => $reason,
                    'notes' => $notes !== '' ? $notes : null,
                    'balance_snapshot' => $this->balanceSnapshot($summary),
                ]),
            ])->save();

            return $lockedRequest->refresh()->load(['settlement.correction']);
        });
    }

    public function cancelAndReleaseReviewRequired(PayoutRequest $payoutRequest, User $reviewer, string $reason, ?string $notes = null): PayoutRequest
    {
        $reason = trim($reason);
        $notes = $notes !== null ? trim($notes) : null;

        if ($reason === '') {
            throw new RuntimeException('Resolution reason is required.');
        }

        return DB::transaction(function () use ($payoutRequest, $reviewer, $reason, $notes): PayoutRequest {
            $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_SETTLEMENT);

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->with(['settlement.correction', 'latestResolution'])
                ->lockForUpdate()
                ->findOrFail($payoutRequest->id);

            $this->assertReviewRequiredRequest($lockedRequest);
            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $summary = $this->buildSummary($lockedRequest->operator_id, $lockedRequest->id);
            $this->assertBalanceConfidenceHealthy($summary, 'Cancel-and-release is blocked because the operator balance is not trustworthy enough yet.');

            $resolvedAt = now();

            PayoutRequestResolution::query()->create([
                'payout_request_id' => $lockedRequest->id,
                'operator_id' => $lockedRequest->operator_id,
                'resolution_type' => PayoutRequestResolution::TYPE_CANCEL_AND_RELEASE,
                'resolved_at' => $resolvedAt,
                'resolved_by_user_id' => $reviewer->id,
                'reason' => $reason,
                'notes' => $notes !== '' ? $notes : null,
                'resulting_status' => PayoutRequest::STATUS_CANCELLED,
                'resulting_settlement_state' => PayoutRequest::SETTLEMENT_STATE_REVERSED,
                'metadata' => [
                    'resolution_balance_snapshot' => $this->balanceSnapshot($summary),
                ],
            ]);

            $lockedRequest->forceFill([
                'status' => PayoutRequest::STATUS_CANCELLED,
                'cancelled_by_user_id' => $reviewer->id,
                'cancelled_at' => $resolvedAt,
                'cancellation_reason' => $reason,
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_REVERSED,
                'settlement_block_reason' => PayoutRequest::SETTLEMENT_BLOCK_REVERSED,
                'settlement_checked_at' => $resolvedAt,
                'settlement_ready_at' => null,
                'provider_status' => 'settlement_reversed_cancelled',
                'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                    'type' => 'review_required_cancelled_and_released',
                    'at' => $resolvedAt->toIso8601String(),
                    'by_user_id' => $reviewer->id,
                    'reason' => $reason,
                    'notes' => $notes !== '' ? $notes : null,
                    'balance_snapshot' => $this->balanceSnapshot($summary),
                ]),
            ])->save();

            return $lockedRequest->refresh()->load(['settlement.correction', 'latestResolution']);
        });
    }

    public function returnReviewRequiredToReview(PayoutRequest $payoutRequest, User $reviewer, string $reason, ?string $notes = null): PayoutRequest
    {
        $reason = trim($reason);
        $notes = $notes !== null ? trim($notes) : null;

        if ($reason === '') {
            throw new RuntimeException('Resolution reason is required.');
        }

        return DB::transaction(function () use ($payoutRequest, $reviewer, $reason, $notes): PayoutRequest {
            $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_SETTLEMENT);

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->with(['settlement.correction', 'latestResolution'])
                ->lockForUpdate()
                ->findOrFail($payoutRequest->id);

            $this->assertReviewRequiredRequest($lockedRequest);
            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $summary = $this->buildSummary($lockedRequest->operator_id, $lockedRequest->id);
            $this->assertBalanceConfidenceHealthy($summary, 'Return-to-review is blocked because the operator balance is not trustworthy enough yet.');

            if ((float) $lockedRequest->amount > (float) $summary['requestable_balance']) {
                throw new RuntimeException('Return-to-review is blocked because the payout amount is no longer fully supported by current operator balance.');
            }

            $resolvedAt = now();

            PayoutRequestResolution::query()->create([
                'payout_request_id' => $lockedRequest->id,
                'operator_id' => $lockedRequest->operator_id,
                'resolution_type' => PayoutRequestResolution::TYPE_RETURN_TO_REVIEW,
                'resolved_at' => $resolvedAt,
                'resolved_by_user_id' => $reviewer->id,
                'reason' => $reason,
                'notes' => $notes !== '' ? $notes : null,
                'resulting_status' => PayoutRequest::STATUS_PENDING_REVIEW,
                'resulting_settlement_state' => PayoutRequest::SETTLEMENT_STATE_NOT_READY,
                'metadata' => [
                    'resolution_balance_snapshot' => $this->balanceSnapshot($summary),
                ],
            ]);

            $lockedRequest->forceFill([
                'status' => PayoutRequest::STATUS_PENDING_REVIEW,
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_NOT_READY,
                'settlement_block_reason' => null,
                'settlement_checked_at' => $resolvedAt,
                'settlement_ready_at' => null,
                'provider_status' => 'reopened_for_review_after_reversal',
                'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                    'type' => 'review_required_returned_to_review',
                    'at' => $resolvedAt->toIso8601String(),
                    'by_user_id' => $reviewer->id,
                    'reason' => $reason,
                    'notes' => $notes !== '' ? $notes : null,
                    'balance_snapshot' => $this->balanceSnapshot($summary),
                ]),
            ])->save();

            return $lockedRequest->refresh()->load(['settlement.correction', 'latestResolution']);
        });
    }

    public function approve(PayoutRequest $payoutRequest, User $reviewer, ?string $notes = null): PayoutRequest
    {
        return DB::transaction(function () use ($payoutRequest, $reviewer, $notes) {
            $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_REVIEW);

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->lockForUpdate()
                ->with('operator')
                ->findOrFail($payoutRequest->id);

            if ($lockedRequest->status !== PayoutRequest::STATUS_PENDING_REVIEW) {
                throw new RuntimeException('Only payout requests pending review can be approved.');
            }

            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $this->syncSettlementStatesForOperator($lockedRequest->operator_id);
            $summary = $this->buildSummary($lockedRequest->operator_id, $lockedRequest->id);
            $this->assertBalanceConfidenceHealthy($summary, 'Payout approval is blocked because the operator balance is not trustworthy enough yet.');

            if ((float) $lockedRequest->amount > (float) $summary['requestable_balance']) {
                throw new RuntimeException('Payout approval is blocked because the reserved amount is no longer fully covered by the operator balance.');
            }

            $lockedRequest->forceFill([
                'status' => PayoutRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
                'settlement_block_reason' => null,
                'settlement_checked_at' => now(),
                'settlement_ready_at' => now(),
                'invalidated_at' => null,
                'invalidated_by_user_id' => null,
                'processing_mode' => PayoutRequest::MODE_MANUAL,
                'provider' => null,
                'provider_transfer_reference' => null,
                'provider_status' => 'approved_unpaid',
                'provider_response' => [
                    'message' => 'Approved for manual payout review. No transfer has been executed.',
                ],
                'paid_at' => null,
                'failure_reason' => null,
                'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                    'type' => 'approved',
                    'at' => now()->toIso8601String(),
                    'by_user_id' => $reviewer->id,
                    'notes' => $notes,
                    'balance_snapshot' => $this->balanceSnapshot($summary),
                ]),
            ])->save();

            return $lockedRequest->refresh();
        });
    }

    public function confirmSettlementHandoff(PayoutRequest $payoutRequest, User $reviewer, string $reason, ?string $notes = null): PayoutRequest
    {
        $reason = trim($reason);
        $notes = $notes !== null ? trim($notes) : null;

        if ($reason === '') {
            throw new RuntimeException('Settlement handoff reason is required.');
        }

        return DB::transaction(function () use ($payoutRequest, $reviewer, $reason, $notes): PayoutRequest {
            $this->operationalReadinessService->assertActionReady(OperationalReadinessService::ACTION_PAYOUT_SETTLEMENT);

            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->with(['settlement.correction', 'latestExecutionAttempt.latestResolution'])
                ->lockForUpdate()
                ->findOrFail($payoutRequest->id);

            $this->lockOperatorBalanceContext($lockedRequest->operator_id);
            $this->syncSettlementStatesForOperator($lockedRequest->operator_id);
            $lockedRequest->refresh()->load(['settlement.correction', 'latestExecutionAttempt.latestResolution']);

            if (! $lockedRequest->latestExecutionAttempt || $lockedRequest->latestExecutionAttempt->execution_state !== PayoutExecutionAttempt::STATE_COMPLETED) {
                throw new RuntimeException('Only payout requests with a completed execution attempt can be handed off for settlement review.');
            }

            if ($lockedRequest->settlement()->exists()) {
                throw new RuntimeException('Settled payout requests do not need a post-execution settlement handoff.');
            }

            if (! in_array($lockedRequest->post_execution_state, [
                PayoutRequest::POST_EXECUTION_STATE_COMPLETED_AWAITING_SETTLEMENT,
                PayoutRequest::POST_EXECUTION_STATE_COMPLETED_BLOCKED_FROM_SETTLEMENT,
            ], true)) {
                throw new RuntimeException('This payout request is not in a post-execution state that supports settlement handoff.');
            }

            if ($lockedRequest->settlement_state !== PayoutRequest::SETTLEMENT_STATE_READY) {
                throw new RuntimeException('Settlement handoff is blocked because this payout request is not currently settlement-ready.');
            }

            $summary = $this->buildSummary($lockedRequest->operator_id, $lockedRequest->id);
            $this->assertBalanceConfidenceHealthy($summary, 'Settlement handoff is blocked because the operator balance is not trustworthy enough yet.');

            if ($lockedRequest->post_execution_handed_off_at !== null) {
                throw new RuntimeException('This payout request has already been handed off for settlement review.');
            }

            $handedOffAt = now();

            $lockedRequest->forceFill([
                'post_execution_handed_off_at' => $handedOffAt,
                'post_execution_handed_off_by_user_id' => $reviewer->id,
                'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                    'type' => 'post_execution_settlement_handoff_confirmed',
                    'at' => $handedOffAt->toIso8601String(),
                    'by_user_id' => $reviewer->id,
                    'reason' => $reason,
                    'notes' => $notes,
                    'execution_reference' => $lockedRequest->latestExecutionAttempt->execution_reference,
                    'balance_snapshot' => $this->balanceSnapshot($summary),
                ]),
            ])->save();

            $this->recordPostExecutionEvent(
                $lockedRequest,
                $lockedRequest->latestExecutionAttempt,
                PayoutPostExecutionEvent::TYPE_SETTLEMENT_HANDOFF_CONFIRMED,
                $reason,
                $notes,
                $lockedRequest->post_execution_state,
                $lockedRequest->settlement_state,
                $reviewer,
                [
                    'execution_reference' => $lockedRequest->latestExecutionAttempt->execution_reference,
                    'balance_snapshot' => $this->balanceSnapshot($summary),
                ],
            );

            return $lockedRequest->refresh()->load(['latestExecutionAttempt.latestResolution', 'latestPostExecutionEvent.eventBy']);
        });
    }

    public function reject(PayoutRequest $payoutRequest, User $reviewer, ?string $notes = null): PayoutRequest
    {
        return DB::transaction(function () use ($payoutRequest, $reviewer, $notes): PayoutRequest {
            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->lockForUpdate()
                ->findOrFail($payoutRequest->id);

            if ($lockedRequest->status !== PayoutRequest::STATUS_PENDING_REVIEW) {
                throw new RuntimeException('Only payout requests pending review can be rejected.');
            }

            $lockedRequest->forceFill([
                'status' => PayoutRequest::STATUS_REJECTED,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_NOT_READY,
                'settlement_block_reason' => null,
                'settlement_checked_at' => now(),
                'settlement_ready_at' => null,
                'invalidated_at' => null,
                'invalidated_by_user_id' => null,
                'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                    'type' => 'rejected',
                    'at' => now()->toIso8601String(),
                    'by_user_id' => $reviewer->id,
                    'notes' => $notes,
                ]),
            ])->save();

            return $lockedRequest->refresh();
        });
    }

    public function cancel(PayoutRequest $payoutRequest, User $reviewer, string $reason): PayoutRequest
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Cancellation reason is required.');
        }

        return DB::transaction(function () use ($payoutRequest, $reviewer, $reason): PayoutRequest {
            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->lockForUpdate()
                ->findOrFail($payoutRequest->id);

            if (! in_array($lockedRequest->status, PayoutRequest::CANCELLABLE_STATUSES, true)) {
                throw new RuntimeException('Only pending-review or approved payout requests can be cancelled.');
            }

            $lockedRequest->forceFill([
                'status' => PayoutRequest::STATUS_CANCELLED,
                'cancelled_by_user_id' => $reviewer->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_NOT_READY,
                'settlement_block_reason' => null,
                'settlement_checked_at' => now(),
                'settlement_ready_at' => null,
                'invalidated_at' => null,
                'invalidated_by_user_id' => null,
                'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                    'type' => 'cancelled',
                    'at' => now()->toIso8601String(),
                    'by_user_id' => $reviewer->id,
                    'reason' => $reason,
                ]),
            ])->save();

            return $lockedRequest->refresh();
        });
    }

    public function returnToReview(PayoutRequest $payoutRequest, User $reviewer, string $reason): PayoutRequest
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Re-review reason is required.');
        }

        return DB::transaction(function () use ($payoutRequest, $reviewer, $reason): PayoutRequest {
            /** @var PayoutRequest $lockedRequest */
            $lockedRequest = PayoutRequest::query()
                ->lockForUpdate()
                ->findOrFail($payoutRequest->id);

            if ($lockedRequest->status !== PayoutRequest::STATUS_APPROVED) {
                throw new RuntimeException('Only approved payout requests can be returned to review.');
            }

            if (! in_array($lockedRequest->settlement_state, [
                PayoutRequest::SETTLEMENT_STATE_BLOCKED_UNDERFUNDED,
                PayoutRequest::SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW,
            ], true)) {
                throw new RuntimeException('Only invalidated approved payout requests can be returned to review.');
            }

            $lockedRequest->forceFill([
                'status' => PayoutRequest::STATUS_PENDING_REVIEW,
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_NOT_READY,
                'settlement_block_reason' => null,
                'settlement_checked_at' => now(),
                'settlement_ready_at' => null,
                'invalidated_at' => null,
                'invalidated_by_user_id' => null,
                'provider_status' => 'returned_to_review',
                'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                    'type' => 'returned_to_review',
                    'at' => now()->toIso8601String(),
                    'by_user_id' => $reviewer->id,
                    'reason' => $reason,
                ]),
            ])->save();

            return $lockedRequest->refresh();
        });
    }

    public function markProcessing(PayoutRequest $payoutRequest, User $reviewer, ?string $notes = null): PayoutRequest
    {
        throw new RuntimeException('Manual payout execution states are disabled until a real settlement phase is implemented.');
    }

    public function markPaid(PayoutRequest $payoutRequest, User $reviewer, ?string $notes = null): PayoutRequest
    {
        throw new RuntimeException('Use the manual settlement action instead. Legacy paid-status transitions are disabled.');
    }

    public function markFailed(PayoutRequest $payoutRequest, User $reviewer, string $reason): PayoutRequest
    {
        throw new RuntimeException('Manual payout failure states are disabled until a real settlement phase is implemented.');
    }

    private function applyExecutionProviderUpdate(
        PayoutExecutionAttempt $lockedAttempt,
        PayoutRequest $lockedRequest,
        array $result,
        string $source,
        ?array $providerPayload = null,
        array $options = [],
    ): PayoutExecutionAttempt {
        $reviewer = $options['reviewer'] ?? null;
        $createStaleResolution = (bool) ($options['create_stale_resolution'] ?? false);
        $eventType = (string) ($options['event_type'] ?? 'execution_attempt_provider_update');

        $appliedAt = now();
        $incomingState = $this->normalizeProviderState((string) ($result['provider_state'] ?? $lockedAttempt->provider_state ?? 'unknown'));
        $incomingExecutionState = $this->normalizeExecutionState($result['execution_state'] ?? $lockedAttempt->execution_state);
        $incomingExternalReference = $result['external_reference'] ?? $lockedAttempt->external_reference;
        $payloadHash = $providerPayload ? hash('sha256', json_encode($providerPayload, JSON_UNESCAPED_SLASHES)) : null;
        $samePayload = $payloadHash !== null && $payloadHash === $lockedAttempt->last_provider_payload_hash;
        $sameProviderState = $incomingState === (string) $lockedAttempt->provider_state;
        $sameExecutionState = $incomingExecutionState === $lockedAttempt->execution_state;
        $sameExternalReference = $incomingExternalReference === $lockedAttempt->external_reference;
        $staleReason = null;
        $ambiguousReason = null;
        $resolutionType = null;
        $resolutionReason = null;
        $resolutionNotes = null;

        if (
            $createStaleResolution
            && $lockedAttempt->isStale($appliedAt)
            && in_array($incomingExecutionState, PayoutExecutionAttempt::ACTIVE_STATES, true)
        ) {
            $incomingExecutionState = PayoutExecutionAttempt::STATE_RETRYABLE_FAILED;
            $staleReason = $lockedAttempt->staleReason()
                ?? 'Execution attempt exceeded the allowed stale threshold during reconciliation.';
            $resolutionType = PayoutExecutionAttemptResolution::TYPE_RECONCILED_STALE;
            $resolutionReason = $staleReason;
            $resolutionNotes = 'Stale attempt was moved into retryable failure. This phase does not assume the payout executed successfully.';
        }

        if ($staleReason === null && $this->shouldFlagExecutionProviderConflict($lockedAttempt, $incomingState, $incomingExecutionState)) {
            $incomingExecutionState = PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED;
            $ambiguousReason = sprintf(
                'Provider state conflict detected. Existing provider state [%s] conflicts with incoming provider state [%s].',
                $lockedAttempt->provider_state ?: 'unknown',
                $incomingState
            );
        }

        $isDuplicateUpdate = ($samePayload || ($source === 'poll' && $sameProviderState && $sameExecutionState && $sameExternalReference))
            && $sameProviderState
            && $sameExecutionState
            && $sameExternalReference;

        if ($isDuplicateUpdate && $staleReason === null && $ambiguousReason === null) {
            return $lockedAttempt->fresh('latestResolution.resolvedBy');
        }

        $providerResponseMetadata = $this->mergeExecutionMetadata(
            $lockedAttempt->provider_response_metadata,
            $result['provider_response_metadata'] ?? null,
            [
                'last_update_source' => $source,
                'last_update_at' => $appliedAt->toIso8601String(),
                'last_provider_state' => $incomingState,
                'last_payload_hash' => $payloadHash,
            ],
        );

        if ($providerPayload !== null) {
            $providerResponseMetadata['last_provider_payload'] = $providerPayload;
        }

        if ($ambiguousReason !== null) {
            $providerResponseMetadata['provider_state_conflict'] = [
                'at' => $appliedAt->toIso8601String(),
                'previous_provider_state' => $lockedAttempt->provider_state,
                'incoming_provider_state' => $incomingState,
                'incoming_execution_state' => $result['execution_state'] ?? null,
                'source' => $source,
            ];
        }

        $lockedAttempt->forceFill([
            'execution_state' => $incomingExecutionState,
            'provider_name' => $result['provider_name'] ?? $lockedAttempt->provider_name,
            'provider_state' => $incomingState,
            'provider_state_source' => $source,
            'provider_state_checked_at' => $appliedAt,
            'last_provider_payload_hash' => $payloadHash ?? $lockedAttempt->last_provider_payload_hash,
            'external_reference' => $incomingExternalReference,
            'provider_response_metadata' => $providerResponseMetadata,
            'last_error' => $staleReason
                ?? $ambiguousReason
                ?? ($result['last_error'] ?? $lockedAttempt->last_error),
            'last_reconciled_at' => $source !== 'dispatch' ? $appliedAt : $lockedAttempt->last_reconciled_at,
            'stale_at' => $staleReason !== null ? $appliedAt : $lockedAttempt->stale_at,
            'completed_at' => in_array($incomingExecutionState, PayoutExecutionAttempt::TERMINAL_STATES, true)
                ? ($result['completed_at'] ?? $lockedAttempt->completed_at ?? $appliedAt)
                : null,
        ])->save();

        if ($resolutionType !== null && $reviewer instanceof User) {
            $summary = $this->buildSummary($lockedRequest->operator_id, $lockedRequest->id);

            $this->recordExecutionResolution(
                $lockedAttempt,
                $lockedRequest,
                $reviewer,
                $resolutionType,
                $resolutionReason,
                $resolutionNotes,
                $incomingExecutionState,
                [
                    'balance_snapshot' => $this->balanceSnapshot($summary),
                ],
            );
        }

        $lockedRequest->forceFill([
            'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                'type' => $eventType,
                'at' => $appliedAt->toIso8601String(),
                'by_user_id' => $reviewer instanceof User ? $reviewer->id : null,
                'execution_reference' => $lockedAttempt->execution_reference,
                'execution_state' => $incomingExecutionState,
                'provider_state' => $incomingState,
                'provider_update_source' => $source,
                'provider_conflict' => $ambiguousReason,
                'provider_payload_hash' => $payloadHash,
            ]),
        ])->save();

        $this->linkProviderNegativeOutcomeIfNeeded(
            $lockedAttempt->refresh(),
            $lockedRequest->refresh(),
            $incomingState,
            $source,
            $providerPayload,
        );

        $this->syncSingleSettlementState(
            $lockedRequest->fresh(['settlement.correction', 'latestExecutionAttempt.latestResolution'])
        );

        return $lockedAttempt->fresh('latestResolution.resolvedBy');
    }

    private function createAndDispatchExecutionAttempt(
        PayoutRequest $lockedRequest,
        User $reviewer,
        ?string $provider,
        ?PayoutExecutionAttempt $parentAttempt,
        string $workflowEventType,
    ): PayoutExecutionAttempt {
        $this->assertPayoutRequestSupportsExecutionLifecycle($lockedRequest);
        $executionProvider = $provider ?: (string) config('payouts.execution_provider', 'manual');
        $this->assertExecutionDispatchPreflight($lockedRequest, $executionProvider);

        $summary = $this->buildSummary($lockedRequest->operator_id, $lockedRequest->id);
        $this->assertBalanceConfidenceHealthy($summary, 'Payout execution is blocked because the operator balance is not trustworthy enough yet.');

        if ((float) $lockedRequest->amount > (float) $summary['requestable_balance']) {
            throw new RuntimeException('Payout execution is blocked because the payout amount is no longer fully supported by current operator balance.');
        }

        $latestAttempt = $lockedRequest->latestExecutionAttempt;

        if ($latestAttempt && $latestAttempt->isActive()) {
            throw new RuntimeException('This payout request already has an active execution attempt.');
        }

        if ($parentAttempt === null && $latestAttempt) {
            throw new RuntimeException('This payout request already has execution history. Use the explicit execution retry or resolution path instead.');
        }

        if ($parentAttempt !== null && (! $latestAttempt || $latestAttempt->id !== $parentAttempt->id)) {
            throw new RuntimeException('Only the latest execution attempt can create the next retry attempt.');
        }

        $attemptNumber = ((int) $lockedRequest->executionAttempts()->lockForUpdate()->count()) + 1;
        $executionReference = sprintf('PXR-%d-%02d', $lockedRequest->id, $attemptNumber);
        $idempotencyKey = sprintf('payout-execution:%d:%02d', $lockedRequest->id, $attemptNumber);
        $triggeredAt = now();

        $attempt = $lockedRequest->executionAttempts()->create([
            'operator_id' => $lockedRequest->operator_id,
            'payout_settlement_id' => $lockedRequest->settlement?->id,
            'parent_attempt_id' => $parentAttempt?->id,
            'amount' => round((float) $lockedRequest->amount, 2),
            'currency' => $lockedRequest->currency,
            'execution_state' => PayoutExecutionAttempt::STATE_PENDING_EXECUTION,
            'execution_reference' => $executionReference,
            'idempotency_key' => $idempotencyKey,
            'triggered_at' => $triggeredAt,
            'triggered_by_user_id' => $reviewer->id,
        ]);

        $adapter = $this->payoutExecutionAdapterFactory->make($executionProvider);
        $result = $adapter->dispatch($lockedRequest, $attempt);
        $resultState = $this->normalizeExecutionState($result['execution_state'] ?? PayoutExecutionAttempt::STATE_TERMINAL_FAILED);

        $attempt->forceFill([
            'execution_state' => $resultState,
            'provider_name' => $result['provider_name'] ?? $adapter->name(),
            'provider_state' => strtolower((string) ($result['provider_state'] ?? 'unknown')),
            'provider_state_source' => 'dispatch',
            'provider_state_checked_at' => $triggeredAt,
            'last_provider_payload_hash' => null,
            'external_reference' => $result['external_reference'] ?? null,
            'provider_request_metadata' => $result['provider_request_metadata'] ?? [
                'message' => 'No provider request metadata was returned.',
            ],
            'provider_response_metadata' => $result['provider_response_metadata'] ?? [
                'message' => 'No provider response metadata was returned.',
            ],
            'last_error' => $result['last_error'] ?? null,
            'completed_at' => in_array($resultState, PayoutExecutionAttempt::TERMINAL_STATES, true)
                ? ($result['completed_at'] ?? $triggeredAt)
                : ($result['completed_at'] ?? null),
            'stale_at' => null,
            'last_reconciled_at' => null,
        ])->save();

        $lockedRequest->forceFill([
            'metadata' => $this->appendWorkflowEvent($lockedRequest->metadata, [
                'type' => $workflowEventType,
                'at' => $triggeredAt->toIso8601String(),
                'by_user_id' => $reviewer->id,
                'execution_reference' => $executionReference,
                'execution_state' => $attempt->execution_state,
                'provider_name' => $attempt->provider_name,
                'idempotency_key' => $idempotencyKey,
                'parent_attempt_id' => $parentAttempt?->id,
                'balance_snapshot' => $this->balanceSnapshot($summary),
            ]),
        ])->save();

        return $attempt->refresh()->load('latestResolution.resolvedBy');
    }

    private function assertExecutionDispatchPreflight(PayoutRequest $payoutRequest, string $provider): void
    {
        $preflight = $this->payoutExecutionOpsService->dispatchPreflight($payoutRequest, $provider);

        if (! $preflight['ready']) {
            throw new RuntimeException($preflight['blocking_reason'] ?? 'Payout execution dispatch is blocked.');
        }
    }

    private function assertRetryBudgetAvailable(PayoutRequest $payoutRequest): void
    {
        $attemptCount = (int) $payoutRequest->executionAttempts()->lockForUpdate()->count();
        $retryCount = max(0, $attemptCount - 1);
        $maxRetryAttempts = $this->payoutExecutionOpsService->maxRetryAttempts();

        if ($retryCount >= $maxRetryAttempts) {
            throw new RuntimeException('Payout execution retry is blocked because the retry budget is exhausted for this payout request.');
        }
    }

    private function assertPayoutRequestSupportsExecutionLifecycle(PayoutRequest $payoutRequest): void
    {
        if ($payoutRequest->status !== PayoutRequest::STATUS_APPROVED
            || $payoutRequest->settlement_state !== PayoutRequest::SETTLEMENT_STATE_READY) {
            throw new RuntimeException('Only approved payout requests with settlement state ready can enter payout execution.');
        }

        if ($payoutRequest->settlement) {
            throw new RuntimeException('Settled payout requests cannot create or retry payout execution attempts.');
        }
    }

    private function assertExecutionAttemptCanBeReconciled(PayoutExecutionAttempt $attempt): void
    {
        if ($attempt->isActive()) {
            return;
        }

        if (
            $attempt->execution_state === PayoutExecutionAttempt::STATE_COMPLETED
            && filled($attempt->provider_name)
            && $attempt->provider_name !== 'manual'
        ) {
            return;
        }

        throw new RuntimeException('Only active or provider-completed execution attempts can be reconciled.');
    }

    private function shouldFlagExecutionProviderConflict(
        PayoutExecutionAttempt $attempt,
        string $incomingProviderState,
        string $incomingExecutionState,
    ): bool {
        if ($attempt->provider_state === null || $attempt->provider_state === '' || $incomingProviderState === '') {
            return false;
        }

        if ($attempt->provider_state === $incomingProviderState) {
            return false;
        }

        if ($this->isNegativeProviderState($incomingProviderState)) {
            return false;
        }

        if ($attempt->execution_state === PayoutExecutionAttempt::STATE_COMPLETED && $incomingExecutionState !== PayoutExecutionAttempt::STATE_COMPLETED) {
            return true;
        }

        if (in_array($attempt->execution_state, [
            PayoutExecutionAttempt::STATE_DISPATCHED,
            PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
        ], true) && $incomingExecutionState === PayoutExecutionAttempt::STATE_COMPLETED) {
            return false;
        }

        return in_array($attempt->execution_state, PayoutExecutionAttempt::TERMINAL_STATES, true)
            && $incomingExecutionState !== $attempt->execution_state;
    }

    private function normalizeExecutionState(string $state): string
    {
        return match ($state) {
            'provider_acknowledged' => PayoutExecutionAttempt::STATE_DISPATCHED,
            'failed' => PayoutExecutionAttempt::STATE_TERMINAL_FAILED,
            default => $state,
        };
    }

    private function normalizeProviderState(string $state): string
    {
        $state = strtolower(trim($state));

        return str_replace([' ', '-'], '_', $state);
    }

    private function mergeExecutionMetadata(?array $existing, ?array $incoming, array $extra = []): array
    {
        return array_filter([
            ...($existing ?? []),
            ...($incoming ?? []),
            ...$extra,
        ], static fn ($value) => $value !== null);
    }

    private function recordExecutionResolution(
        PayoutExecutionAttempt $attempt,
        PayoutRequest $payoutRequest,
        User $reviewer,
        string $type,
        string $reason,
        ?string $notes,
        string $resultingState,
        array $metadata = [],
    ): PayoutExecutionAttemptResolution {
        return PayoutExecutionAttemptResolution::query()->create([
            'payout_execution_attempt_id' => $attempt->id,
            'payout_request_id' => $payoutRequest->id,
            'operator_id' => $payoutRequest->operator_id,
            'resolution_type' => $type,
            'resolved_at' => now(),
            'resolved_by_user_id' => $reviewer->id,
            'reason' => $reason,
            'notes' => $notes,
            'resulting_state' => $resultingState,
            'metadata' => $metadata,
        ]);
    }

    private function buildSummary(int $operatorId, ?int $excludePayoutRequestId = null): array
    {
        $operator = Operator::query()->findOrFail($operatorId);
        $accounting = $this->operatorAccountingService->summary($operator);

        $pendingReviewReserved = $this->sumPayoutRequestsByStatus($operatorId, [PayoutRequest::STATUS_PENDING_REVIEW], $excludePayoutRequestId);
        $approvedUnpaidReserved = $this->sumPayoutRequestsByStatus($operatorId, [PayoutRequest::STATUS_APPROVED], $excludePayoutRequestId);
        $reviewRequiredReserved = $this->sumPayoutRequestsByStatus($operatorId, [PayoutRequest::STATUS_REVIEW_REQUIRED], $excludePayoutRequestId);
        $reservedForPayout = round($pendingReviewReserved + $approvedUnpaidReserved + $reviewRequiredReserved, 2);

        $paidOut = (float) PayoutSettlement::query()
            ->where('operator_id', $operatorId)
            ->whereDoesntHave('correction')
            ->when($excludePayoutRequestId, fn ($query) => $query->where('payout_request_id', '!=', $excludePayoutRequestId))
            ->sum('amount');

        $payableBasis = (float) ($accounting['payable_basis'] ?? $accounting['net_payable_fees']);
        $requestableBalance = (float) round(max(0.0, $payableBasis - $paidOut - $reservedForPayout), 2);

        return [
            'earnings' => round($payableBasis, 2),
            'gross_sales' => round((float) ($accounting['gross_sales'] ?? 0), 2),
            'net_sales' => round((float) ($accounting['net_sales'] ?? 0), 2),
            'paid_sales_count' => (int) ($accounting['paid_sales_count'] ?? 0),
            'payable_basis' => round($payableBasis, 2),
            'gross_billed_fees' => round((float) $accounting['gross_billed_fees'], 2),
            'reversed_fees' => round((float) $accounting['reversed_fees'], 2),
            'blocked_fees' => round((float) $accounting['blocked_fees'], 2),
            'net_payable_fees' => round((float) $accounting['net_payable_fees'], 2),
            'unresolved_blocked_count' => (int) $accounting['unresolved_blocked_count'],
            'confidence_state' => $accounting['confidence_state'],
            'confidence_reasons' => $accounting['confidence_reasons'],
            'statement_currency' => $accounting['currency'],
            'paid_out' => round($paidOut, 2),
            'settled_total' => round($paidOut, 2),
            'settled_request_count' => PayoutSettlement::query()
                ->where('operator_id', $operatorId)
                ->whereDoesntHave('correction')
                ->when($excludePayoutRequestId, fn ($query) => $query->where('payout_request_id', '!=', $excludePayoutRequestId))
                ->count(),
            'pending_review_reserved' => round($pendingReviewReserved, 2),
            'approved_unpaid_reserved' => round($approvedUnpaidReserved, 2),
            'review_required_reserved' => round($reviewRequiredReserved, 2),
            'reserved_for_payout' => round($reservedForPayout, 2),
            'reserved' => round($reservedForPayout, 2),
            'requestable_balance' => $requestableBalance,
            'available_balance' => $requestableBalance,
            'invalidated_approved_count' => PayoutRequest::query()
                ->where('operator_id', $operatorId)
                ->where('status', PayoutRequest::STATUS_APPROVED)
                ->whereIn('settlement_state', [
                    PayoutRequest::SETTLEMENT_STATE_BLOCKED_UNDERFUNDED,
                    PayoutRequest::SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW,
                ])
                ->count(),
            'execution_in_flight_count' => PayoutExecutionAttempt::query()
                ->where('operator_id', $operatorId)
                ->whereIn('execution_state', PayoutExecutionAttempt::ACTIVE_STATES)
                ->count(),
            'completed_awaiting_settlement_count' => PayoutRequest::query()
                ->where('operator_id', $operatorId)
                ->where('post_execution_state', PayoutRequest::POST_EXECUTION_STATE_COMPLETED_AWAITING_SETTLEMENT)
                ->count(),
            'post_execution_exception_count' => PayoutRequest::query()
                ->where('operator_id', $operatorId)
                ->whereIn('post_execution_state', [
                    PayoutRequest::POST_EXECUTION_STATE_COMPLETED_BLOCKED_FROM_SETTLEMENT,
                    PayoutRequest::POST_EXECUTION_STATE_FAILED_RETRYABLE,
                    PayoutRequest::POST_EXECUTION_STATE_MANUAL_FOLLOWUP_REQUIRED,
                    PayoutRequest::POST_EXECUTION_STATE_TERMINAL_FAILED,
                    PayoutRequest::POST_EXECUTION_STATE_PROVIDER_RETURNED,
                    PayoutRequest::POST_EXECUTION_STATE_PROVIDER_REVERSED,
                    PayoutRequest::POST_EXECUTION_STATE_PROVIDER_REJECTED,
                    PayoutRequest::POST_EXECUTION_STATE_PROVIDER_ON_HOLD,
                ])
                ->count(),
        ];
    }

    private function sumPayoutRequestsByStatus(int $operatorId, array $statuses, ?int $excludePayoutRequestId = null): float
    {
        return (float) PayoutRequest::query()
            ->where('operator_id', $operatorId)
            ->whereIn('status', $statuses)
            ->when($excludePayoutRequestId, fn ($query) => $query->where('id', '!=', $excludePayoutRequestId))
            ->sum('amount');
    }

    private function lockOperatorBalanceContext(int $operatorId): Operator
    {
        $lockedOperator = Operator::query()->lockForUpdate()->findOrFail($operatorId);

        PayoutRequest::query()
            ->where('operator_id', $operatorId)
            ->lockForUpdate()
            ->get(['id']);

        PayoutExecutionAttempt::query()
            ->where('operator_id', $operatorId)
            ->lockForUpdate()
            ->get(['id']);

        PayoutExecutionAttemptResolution::query()
            ->where('operator_id', $operatorId)
            ->lockForUpdate()
            ->get(['id']);

        PayoutPostExecutionEvent::query()
            ->where('operator_id', $operatorId)
            ->lockForUpdate()
            ->get(['id']);

        PayoutSettlement::query()
            ->where('operator_id', $operatorId)
            ->lockForUpdate()
            ->get(['id']);

        PayoutSettlementCorrection::query()
            ->where('operator_id', $operatorId)
            ->lockForUpdate()
            ->get(['id']);

        PayoutRequestResolution::query()
            ->where('operator_id', $operatorId)
            ->lockForUpdate()
            ->get(['id']);

        BillingLedgerEntry::query()
            ->where('operator_id', $operatorId)
            ->lockForUpdate()
            ->get(['id']);

        AccessPoint::query()
            ->whereIn('id', BillingLedgerEntry::query()
                ->select('access_point_id')
                ->where('operator_id', $operatorId)
                ->whereNotNull('access_point_id')
                ->distinct())
            ->lockForUpdate()
            ->get(['id']);

        return $lockedOperator;
    }

    private function syncSettlementStatesForOperator(int $operatorId): void
    {
        $this->lockOperatorBalanceContext($operatorId);

        PayoutRequest::query()
            ->where('operator_id', $operatorId)
            ->orderBy('requested_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (PayoutRequest $payoutRequest): void {
                $this->syncSingleSettlementState($payoutRequest);
            });
    }

    private function syncSingleSettlementState(PayoutRequest $payoutRequest): void
    {
        $now = now();
        $status = $payoutRequest->status;
        $summary = null;
        $settlementState = PayoutRequest::SETTLEMENT_STATE_NOT_READY;
        $blockReason = null;
        $invalidatedAt = null;
        $invalidate = false;

        $settlement = $payoutRequest->relationLoaded('settlement')
            ? $payoutRequest->settlement
            : $payoutRequest->settlement()->with('correction')->first();
        $hasSettlement = $settlement !== null;
        $hasSettlementCorrection = $settlement?->correction !== null;
        $latestExecutionAttempt = $payoutRequest->relationLoaded('latestExecutionAttempt')
            ? $payoutRequest->latestExecutionAttempt
            : $payoutRequest->latestExecutionAttempt()->with('latestResolution')->first();
        $hasNegativeProviderOutcome = $latestExecutionAttempt?->hasNegativeProviderOutcome() ?? false;
        $providerNegativeReviewHold = str_starts_with((string) $payoutRequest->provider_status, 'provider_')
            && str_ends_with((string) $payoutRequest->provider_status, '_under_review');

        if ($status === PayoutRequest::STATUS_REVIEW_REQUIRED) {
            $settlementState = PayoutRequest::SETTLEMENT_STATE_REVERSED;
            $blockReason = PayoutRequest::SETTLEMENT_BLOCK_REVERSED;
            $invalidate = true;
        } elseif ($status === PayoutRequest::STATUS_CANCELLED && $hasSettlementCorrection) {
            $settlementState = PayoutRequest::SETTLEMENT_STATE_REVERSED;
            $blockReason = PayoutRequest::SETTLEMENT_BLOCK_REVERSED;
            $invalidate = true;
        } elseif ($hasSettlementCorrection && in_array($status, PayoutRequest::SETTLED_STATUSES, true)) {
            $settlementState = PayoutRequest::SETTLEMENT_STATE_REVERSED;
            $blockReason = PayoutRequest::SETTLEMENT_BLOCK_REVERSED;
            $invalidate = true;
        } elseif (in_array($status, PayoutRequest::SETTLED_STATUSES, true) || $hasSettlement) {
            $settlementState = PayoutRequest::SETTLEMENT_STATE_SETTLED;
        } elseif (in_array($status, [PayoutRequest::STATUS_PROCESSING, PayoutRequest::STATUS_FAILED], true)) {
            $settlementState = PayoutRequest::SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW;
            $blockReason = PayoutRequest::SETTLEMENT_BLOCK_LEGACY_EXECUTION_STATUS;
            $invalidate = true;
        } elseif ($status === PayoutRequest::STATUS_APPROVED && ($providerNegativeReviewHold || ($hasNegativeProviderOutcome && blank($payoutRequest->provider_status)))) {
            $settlementState = PayoutRequest::SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW;
            $blockReason = PayoutRequest::SETTLEMENT_BLOCK_PROVIDER_NEGATIVE_OUTCOME;
            $invalidate = true;
        } elseif ($status === PayoutRequest::STATUS_APPROVED) {
            $summary = $this->buildSummary($payoutRequest->operator_id, $payoutRequest->id);

            if (($summary['confidence_state'] ?? 'degraded') !== 'healthy') {
                $settlementState = PayoutRequest::SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW;
                $blockReason = PayoutRequest::SETTLEMENT_BLOCK_CONFIDENCE_DEGRADED;
                $invalidate = true;
            } elseif ((float) $payoutRequest->amount > (float) $summary['requestable_balance']) {
                $settlementState = PayoutRequest::SETTLEMENT_STATE_BLOCKED_UNDERFUNDED;
                $blockReason = PayoutRequest::SETTLEMENT_BLOCK_UNDERFUNDED;
                $invalidate = true;
            } else {
                $settlementState = PayoutRequest::SETTLEMENT_STATE_READY;
            }
        }

        $updates = [
            'settlement_state' => $settlementState,
            'settlement_block_reason' => $blockReason,
            'settlement_checked_at' => $now,
            'settlement_ready_at' => $settlementState === PayoutRequest::SETTLEMENT_STATE_READY
                ? ($payoutRequest->settlement_ready_at ?? $now)
                : null,
        ];

        if ($invalidate) {
            $updates['invalidated_at'] = $payoutRequest->invalidated_at ?? $now;
        } elseif (! in_array($status, PayoutRequest::SETTLED_STATUSES, true)) {
            $updates['invalidated_at'] = null;
            $updates['invalidated_by_user_id'] = null;
        }

        $shouldWrite = $payoutRequest->settlement_state !== $settlementState
            || $payoutRequest->settlement_block_reason !== $blockReason
            || ($invalidate && $payoutRequest->invalidated_at === null);

        if ($shouldWrite) {
            $eventType = match ($settlementState) {
                PayoutRequest::SETTLEMENT_STATE_READY => 'settlement_ready',
                PayoutRequest::SETTLEMENT_STATE_BLOCKED_UNDERFUNDED => 'settlement_invalidated_underfunded',
                PayoutRequest::SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW => 'settlement_invalidated_manual_review',
                PayoutRequest::SETTLEMENT_STATE_REVERSED => 'settlement_reversed_review_required',
                PayoutRequest::SETTLEMENT_STATE_SETTLED => 'settlement_settled_legacy',
                default => 'settlement_not_ready',
            };

            $updates['metadata'] = $this->appendWorkflowEvent($payoutRequest->metadata, [
                'type' => $eventType,
                'at' => $now->toIso8601String(),
                'reason' => $blockReason,
                'balance_snapshot' => $summary ? $this->balanceSnapshot($summary) : null,
            ]);
        }

        $postExecution = $this->resolvePostExecutionState(
            $payoutRequest,
            $latestExecutionAttempt,
            $settlementState,
            $blockReason,
        );

        $updates['post_execution_state'] = $postExecution['state'];
        $updates['post_execution_reason'] = $postExecution['reason'];
        $updates['post_execution_updated_at'] = $postExecution['state'] !== null ? $now : null;

        if (! in_array($postExecution['state'], [
            PayoutRequest::POST_EXECUTION_STATE_COMPLETED_AWAITING_SETTLEMENT,
            PayoutRequest::POST_EXECUTION_STATE_COMPLETED_BLOCKED_FROM_SETTLEMENT,
        ], true)) {
            $updates['post_execution_handed_off_at'] = null;
            $updates['post_execution_handed_off_by_user_id'] = null;
        }

        $postExecutionChanged = $payoutRequest->post_execution_state !== $postExecution['state']
            || $payoutRequest->post_execution_reason !== $postExecution['reason'];

        if ($postExecutionChanged) {
            $updates['metadata'] = $this->appendWorkflowEvent($updates['metadata'] ?? $payoutRequest->metadata, [
                'type' => 'post_execution_state_synced',
                'at' => $now->toIso8601String(),
                'post_execution_state' => $postExecution['state'],
                'post_execution_reason' => $postExecution['reason'],
                'settlement_state' => $settlementState,
                'execution_reference' => $latestExecutionAttempt?->execution_reference,
            ]);
        }

        $payoutRequest->forceFill($updates)->save();

        if ($postExecutionChanged) {
            $this->recordPostExecutionEvent(
                $payoutRequest->refresh(),
                $latestExecutionAttempt,
                PayoutPostExecutionEvent::TYPE_STATE_SYNC,
                $postExecution['reason'] ?? 'Post-execution state cleared.',
                null,
                $postExecution['state'],
                $settlementState,
                null,
                [
                    'settlement_block_reason' => $blockReason,
                    'execution_state' => $latestExecutionAttempt?->execution_state,
                    'execution_reference' => $latestExecutionAttempt?->execution_reference,
                ],
            );
        }
    }

    private function resolvePostExecutionState(
        PayoutRequest $payoutRequest,
        ?PayoutExecutionAttempt $latestExecutionAttempt,
        string $settlementState,
        ?string $blockReason,
    ): array {
        if (in_array($payoutRequest->status, [
            PayoutRequest::STATUS_CANCELLED,
            PayoutRequest::STATUS_REJECTED,
        ], true)) {
            return ['state' => null, 'reason' => null];
        }

        if (! $latestExecutionAttempt) {
            return ['state' => null, 'reason' => null];
        }

        if ($latestExecutionAttempt->hasNegativeProviderOutcome()) {
            return $this->providerNegativePostExecutionState(
                (string) $latestExecutionAttempt->provider_state,
                $payoutRequest->settlement !== null
            );
        }

        if ($payoutRequest->settlement || $payoutRequest->status === PayoutRequest::STATUS_SETTLED) {
            return ['state' => null, 'reason' => null];
        }

        return match ($latestExecutionAttempt->execution_state) {
            PayoutExecutionAttempt::STATE_COMPLETED => $settlementState === PayoutRequest::SETTLEMENT_STATE_READY
                ? [
                    'state' => PayoutRequest::POST_EXECUTION_STATE_COMPLETED_AWAITING_SETTLEMENT,
                    'reason' => 'Provider execution completed. Admin must explicitly hand off and record internal settlement.',
                ]
                : [
                    'state' => PayoutRequest::POST_EXECUTION_STATE_COMPLETED_BLOCKED_FROM_SETTLEMENT,
                    'reason' => 'Provider execution completed, but internal settlement is currently blocked. '.($blockReason ?: 'Manual review is required.'),
                ],
            PayoutExecutionAttempt::STATE_RETRYABLE_FAILED => [
                'state' => PayoutRequest::POST_EXECUTION_STATE_FAILED_RETRYABLE,
                'reason' => 'Provider execution failed in a retryable way. Use reconcile or retry instead of pretending it settled.',
            ],
            PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED => [
                'state' => PayoutRequest::POST_EXECUTION_STATE_MANUAL_FOLLOWUP_REQUIRED,
                'reason' => 'Provider execution needs manual follow-up before any settlement step is safe.',
            ],
            PayoutExecutionAttempt::STATE_TERMINAL_FAILED => [
                'state' => PayoutRequest::POST_EXECUTION_STATE_TERMINAL_FAILED,
                'reason' => 'Provider execution is terminally failed. This payout request cannot move forward until admin resolves it.',
            ],
            default => ['state' => null, 'reason' => null],
        };
    }

    private function providerNegativePostExecutionState(string $providerState, bool $hasSettlement): array
    {
        return match ($providerState) {
            PayoutExecutionAttempt::PROVIDER_STATE_RETURNED => [
                'state' => PayoutRequest::POST_EXECUTION_STATE_PROVIDER_RETURNED,
                'reason' => $hasSettlement
                    ? 'Provider reported the payout as returned after internal settlement activity. Settlement correction and manual review are now required.'
                    : 'Provider reported the payout as returned. Do not settle this payout request until admin reviews the provider exception.',
            ],
            PayoutExecutionAttempt::PROVIDER_STATE_REVERSED => [
                'state' => PayoutRequest::POST_EXECUTION_STATE_PROVIDER_REVERSED,
                'reason' => $hasSettlement
                    ? 'Provider reported the payout as reversed after internal settlement activity. Settlement correction and manual review are now required.'
                    : 'Provider reported the payout as reversed. Do not settle this payout request until admin reviews the provider exception.',
            ],
            PayoutExecutionAttempt::PROVIDER_STATE_REJECTED => [
                'state' => PayoutRequest::POST_EXECUTION_STATE_PROVIDER_REJECTED,
                'reason' => $hasSettlement
                    ? 'Provider rejected the payout after an earlier positive state. Settlement correction and manual review are now required.'
                    : 'Provider rejected this payout after execution activity. Keep it blocked and under admin review.',
            ],
            PayoutExecutionAttempt::PROVIDER_STATE_ON_HOLD,
            PayoutExecutionAttempt::PROVIDER_STATE_COMPLIANCE_HOLD => [
                'state' => PayoutRequest::POST_EXECUTION_STATE_PROVIDER_ON_HOLD,
                'reason' => $hasSettlement
                    ? 'Provider placed this payout under hold after internal settlement activity. Manual review is required before treating the payout as valid.'
                    : 'Provider placed this payout under hold. Manual review is required before any settlement step is safe.',
            ],
            default => [
                'state' => PayoutRequest::POST_EXECUTION_STATE_MANUAL_FOLLOWUP_REQUIRED,
                'reason' => 'Provider reported a negative payout outcome that requires manual follow-up.',
            ],
        };
    }

    private function linkProviderNegativeOutcomeIfNeeded(
        PayoutExecutionAttempt $attempt,
        PayoutRequest $payoutRequest,
        string $providerState,
        string $source,
        ?array $providerPayload = null,
    ): void {
        if (! $this->isNegativeProviderState($providerState)) {
            return;
        }

        $correctionType = $this->providerNegativeCorrectionType($providerState);
        $reason = $this->providerNegativeOutcomeReason($providerState, $attempt->provider_name ?: 'provider');
        $metadata = [
            'provider_state' => $providerState,
            'provider_name' => $attempt->provider_name,
            'provider_state_source' => $source,
            'execution_reference' => $attempt->execution_reference,
            'external_reference' => $attempt->external_reference,
            'provider_payload' => $providerPayload,
        ];

        if ($payoutRequest->settlement && ! $payoutRequest->settlement->correction) {
            $correctedAt = now();

            PayoutSettlementCorrection::query()->create([
                'payout_settlement_id' => $payoutRequest->settlement->id,
                'payout_request_id' => $payoutRequest->id,
                'operator_id' => $payoutRequest->operator_id,
                'correction_type' => $correctionType,
                'corrected_at' => $correctedAt,
                'corrected_by_user_id' => $attempt->triggered_by_user_id ?: $payoutRequest->settlement->settled_by_user_id,
                'reason' => $reason,
                'notes' => null,
                'metadata' => [
                    'automated_from_provider_update' => true,
                    'provider_negative_outcome' => $metadata,
                    'settlement_snapshot' => [
                        'amount' => round((float) $payoutRequest->settlement->amount, 2),
                        'currency' => $payoutRequest->settlement->currency,
                        'settled_at' => optional($payoutRequest->settlement->settled_at)?->toIso8601String(),
                        'settlement_reference' => $payoutRequest->settlement->settlement_reference,
                    ],
                ],
            ]);

            $payoutRequest->forceFill([
                'status' => PayoutRequest::STATUS_REVIEW_REQUIRED,
                'settlement_state' => PayoutRequest::SETTLEMENT_STATE_REVERSED,
                'settlement_block_reason' => PayoutRequest::SETTLEMENT_BLOCK_REVERSED,
                'settlement_checked_at' => $correctedAt,
                'settlement_ready_at' => null,
                'invalidated_at' => $payoutRequest->invalidated_at ?? $correctedAt,
                'invalidated_by_user_id' => null,
                'provider_status' => "provider_{$providerState}_review_required",
                'metadata' => $this->appendWorkflowEvent($payoutRequest->metadata, [
                    'type' => 'provider_negative_outcome_linked_to_settlement_correction',
                    'at' => $correctedAt->toIso8601String(),
                    'provider_state' => $providerState,
                    'execution_reference' => $attempt->execution_reference,
                    'external_reference' => $attempt->external_reference,
                    'reason' => $reason,
                    'provider_update_source' => $source,
                ]),
            ])->save();

            $this->recordPostExecutionEvent(
                $payoutRequest->refresh(),
                $attempt,
                PayoutPostExecutionEvent::TYPE_PROVIDER_NEGATIVE_OUTCOME_LINKED,
                $reason,
                null,
                $this->providerNegativePostExecutionState($providerState, true)['state'],
                PayoutRequest::SETTLEMENT_STATE_REVERSED,
                null,
                $metadata
            );

            return;
        }

        $payoutRequest->forceFill([
            'provider_status' => "provider_{$providerState}_under_review",
            'metadata' => $this->appendWorkflowEvent($payoutRequest->metadata, [
                'type' => 'provider_negative_outcome_recorded',
                'at' => now()->toIso8601String(),
                'provider_state' => $providerState,
                'execution_reference' => $attempt->execution_reference,
                'external_reference' => $attempt->external_reference,
                'reason' => $reason,
                'provider_update_source' => $source,
            ]),
        ])->save();
    }

    private function isNegativeProviderState(string $providerState): bool
    {
        return in_array($providerState, PayoutExecutionAttempt::NEGATIVE_PROVIDER_STATES, true);
    }

    private function providerNegativeCorrectionType(string $providerState): string
    {
        return match ($providerState) {
            PayoutExecutionAttempt::PROVIDER_STATE_RETURNED => PayoutSettlementCorrection::TYPE_PROVIDER_RETURN,
            PayoutExecutionAttempt::PROVIDER_STATE_REVERSED => PayoutSettlementCorrection::TYPE_PROVIDER_REVERSAL,
            PayoutExecutionAttempt::PROVIDER_STATE_ON_HOLD,
            PayoutExecutionAttempt::PROVIDER_STATE_COMPLIANCE_HOLD => PayoutSettlementCorrection::TYPE_PROVIDER_HOLD,
            default => PayoutSettlementCorrection::TYPE_PROVIDER_REJECTION,
        };
    }

    private function providerNegativeOutcomeReason(string $providerState, string $providerName): string
    {
        $providerLabel = strtoupper($providerName);

        return match ($providerState) {
            PayoutExecutionAttempt::PROVIDER_STATE_RETURNED => "{$providerLabel} reported the payout as returned after an earlier execution update.",
            PayoutExecutionAttempt::PROVIDER_STATE_REVERSED => "{$providerLabel} reported the payout as reversed after an earlier execution update.",
            PayoutExecutionAttempt::PROVIDER_STATE_REJECTED => "{$providerLabel} reported the payout as rejected after an earlier execution update.",
            PayoutExecutionAttempt::PROVIDER_STATE_ON_HOLD,
            PayoutExecutionAttempt::PROVIDER_STATE_COMPLIANCE_HOLD => "{$providerLabel} reported the payout as on hold after an earlier execution update.",
            default => "{$providerLabel} reported a negative payout outcome that requires manual review.",
        };
    }

    private function recordPostExecutionEvent(
        PayoutRequest $payoutRequest,
        ?PayoutExecutionAttempt $attempt,
        string $eventType,
        string $reason,
        ?string $notes,
        ?string $resultingState,
        ?string $resultingSettlementState,
        ?User $user,
        array $metadata = [],
    ): PayoutPostExecutionEvent {
        return PayoutPostExecutionEvent::query()->create([
            'payout_request_id' => $payoutRequest->id,
            'payout_execution_attempt_id' => $attempt?->id,
            'operator_id' => $payoutRequest->operator_id,
            'event_type' => $eventType,
            'event_at' => now(),
            'event_by_user_id' => $user?->id,
            'reason' => $reason,
            'notes' => $notes,
            'resulting_post_execution_state' => $resultingState,
            'resulting_settlement_state' => $resultingSettlementState,
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);
    }

    private function assertBalanceConfidenceHealthy(array $summary, string $prefix): void
    {
        if (($summary['confidence_state'] ?? 'degraded') === 'healthy') {
            return;
        }

        $reasons = array_filter($summary['confidence_reasons'] ?? []);
        $message = $prefix;

        if ($reasons !== []) {
            $message .= ' '.implode(' ', $reasons);
        }

        throw new RuntimeException(trim($message));
    }

    private function balanceSnapshot(array $summary): array
    {
        return [
            'gross_sales' => $summary['gross_sales'] ?? 0,
            'net_sales' => $summary['net_sales'] ?? 0,
            'paid_sales_count' => $summary['paid_sales_count'] ?? 0,
            'payable_basis' => $summary['payable_basis'] ?? $summary['net_payable_fees'],
            'gross_billed_fees' => $summary['gross_billed_fees'],
            'reversed_fees' => $summary['reversed_fees'],
            'blocked_fees' => $summary['blocked_fees'],
            'net_payable_fees' => $summary['net_payable_fees'],
            'paid_out' => $summary['paid_out'],
            'pending_review_reserved' => $summary['pending_review_reserved'],
            'approved_unpaid_reserved' => $summary['approved_unpaid_reserved'],
            'review_required_reserved' => $summary['review_required_reserved'],
            'reserved_for_payout' => $summary['reserved_for_payout'],
            'requestable_balance' => $summary['requestable_balance'],
            'confidence_state' => $summary['confidence_state'],
            'confidence_reasons' => $summary['confidence_reasons'],
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private function assertReviewRequiredRequest(PayoutRequest $payoutRequest): void
    {
        if ($payoutRequest->status !== PayoutRequest::STATUS_REVIEW_REQUIRED) {
            throw new RuntimeException('Only review-required payout requests can be resolved through this path.');
        }

        if ($payoutRequest->settlement_state !== PayoutRequest::SETTLEMENT_STATE_REVERSED) {
            throw new RuntimeException('Only review-required payout requests with reversed settlement state can be resolved.');
        }

        $settlement = $payoutRequest->relationLoaded('settlement')
            ? $payoutRequest->settlement
            : $payoutRequest->settlement()->with('correction')->first();

        if (! $settlement || ! $settlement->correction) {
            throw new RuntimeException('This payout request does not have a reversed settlement to resolve.');
        }
    }

    private function appendWorkflowEvent(?array $metadata, array $event): array
    {
        $metadata = $metadata ?? [];
        $events = $metadata['workflow_events'] ?? [];
        $events[] = $event;
        $metadata['workflow_events'] = array_slice($events, -20);

        return $metadata;
    }
}
