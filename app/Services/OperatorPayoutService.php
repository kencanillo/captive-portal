<?php

namespace App\Services;

use App\Models\Operator;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\WifiSession;
use App\Services\Payouts\ManualPayoutProcessor;
use App\Services\Payouts\PayMongoTransferPayoutProcessor;
use App\Services\Payouts\PayoutProcessor;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OperatorPayoutService
{
    public function __construct(
        private readonly ManualPayoutProcessor $manualProcessor,
        private readonly PayMongoTransferPayoutProcessor $payMongoProcessor,
        private readonly ServiceFeeCalculator $serviceFeeCalculator,
    ) {
    }

    public function summary(Operator $operator): array
    {
        $siteIds = $operator->sites()->select('id');

        $earnings = (float) WifiSession::query()
            ->whereIn('site_id', $siteIds)
            ->where('payment_status', WifiSession::STATUS_PAID)
            ->sum('amount_paid');

        $paidOut = (float) $operator->payoutRequests()
            ->where('status', PayoutRequest::STATUS_PAID)
            ->sum('amount');

        $reserved = (float) $operator->payoutRequests()
            ->whereIn('status', [
                PayoutRequest::STATUS_PENDING,
                PayoutRequest::STATUS_APPROVED,
                PayoutRequest::STATUS_PROCESSING,
            ])
            ->sum('amount');

        // Calculate service fee and net earnings
        $feeDetails = $this->serviceFeeCalculator->getFeeDetails($operator, $earnings);
        $serviceFee = $feeDetails['fee_amount'];
        $netEarnings = $feeDetails['net_amount'];

        return [
            'earnings' => round($earnings, 2),
            'service_fee' => round($serviceFee, 2),
            'service_fee_rate' => $feeDetails['fee_rate'],
            'service_fee_percentage' => $feeDetails['fee_rate_percentage'],
            'net_earnings' => round($netEarnings, 2),
            'paid_out' => round($paidOut, 2),
            'reserved' => round($reserved, 2),
            'available_balance' => round(max(0, $netEarnings - $paidOut - $reserved), 2),
            'fee_details' => $feeDetails,
        ];
    }

    public function createRequest(Operator $operator, array $attributes): PayoutRequest
    {
        $summary = $this->summary($operator);
        $amount = round((float) $attributes['amount'], 2);

        if ($amount <= 0) {
            throw new RuntimeException('Payout amount must be greater than zero.');
        }

        if ($amount > $summary['available_balance']) {
            throw new RuntimeException('Payout amount exceeds the available balance after service fees.');
        }

        return $operator->payoutRequests()->create([
            'amount' => $amount,
            'currency' => (string) config('payouts.currency', 'PHP'),
            'status' => PayoutRequest::STATUS_PENDING,
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
        ]);
    }

    public function approve(PayoutRequest $payoutRequest, User $reviewer, ?string $notes = null): PayoutRequest
    {
        if ($payoutRequest->status !== PayoutRequest::STATUS_PENDING) {
            throw new RuntimeException('Only pending payout requests can be approved.');
        }

        return DB::transaction(function () use ($payoutRequest, $reviewer, $notes) {
            $processor = $this->resolveProcessor();
            $processorResult = $processor->process($payoutRequest);

            $payoutRequest->forceFill([
                'status' => $processorResult['status'] ?? PayoutRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'processing_mode' => $processorResult['processing_mode'] ?? $processor->mode(),
                'provider' => $processorResult['provider'] ?? $payoutRequest->provider,
                'provider_transfer_reference' => $processorResult['provider_transfer_reference'] ?? $payoutRequest->provider_transfer_reference,
                'provider_status' => $processorResult['provider_status'] ?? $payoutRequest->provider_status,
                'provider_response' => $processorResult['provider_response'] ?? $payoutRequest->provider_response,
                'paid_at' => $processorResult['paid_at'] ?? null,
                'failure_reason' => null,
            ])->save();

            return $payoutRequest->refresh();
        });
    }

    public function reject(PayoutRequest $payoutRequest, User $reviewer, ?string $notes = null): PayoutRequest
    {
        if ($payoutRequest->status !== PayoutRequest::STATUS_PENDING) {
            throw new RuntimeException('Only pending payout requests can be rejected.');
        }

        $payoutRequest->forceFill([
            'status' => PayoutRequest::STATUS_REJECTED,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ])->save();

        return $payoutRequest->refresh();
    }

    public function markProcessing(PayoutRequest $payoutRequest, User $reviewer, ?string $notes = null): PayoutRequest
    {
        return $this->markState($payoutRequest, $reviewer, PayoutRequest::STATUS_PROCESSING, $notes);
    }

    public function markPaid(PayoutRequest $payoutRequest, User $reviewer, ?string $notes = null): PayoutRequest
    {
        return $this->markState($payoutRequest, $reviewer, PayoutRequest::STATUS_PAID, $notes, now());
    }

    public function markFailed(PayoutRequest $payoutRequest, User $reviewer, string $reason): PayoutRequest
    {
        $payoutRequest->forceFill([
            'status' => PayoutRequest::STATUS_FAILED,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => $payoutRequest->reviewed_at ?? now(),
            'review_notes' => $reason,
            'failure_reason' => $reason,
            'paid_at' => null,
        ])->save();

        return $payoutRequest->refresh();
    }

    public function fallbackToManual(PayoutRequest $payoutRequest, User $reviewer, string $reason): PayoutRequest
    {
        $payoutRequest->forceFill([
            'status' => PayoutRequest::STATUS_APPROVED,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
            'processing_mode' => PayoutRequest::MODE_MANUAL,
            'provider' => (string) config('payouts.provider', 'paymongo'),
            'provider_status' => 'manual_review_required',
            'failure_reason' => $reason,
            'provider_response' => [
                'message' => $reason,
            ],
        ])->save();

        return $payoutRequest->refresh();
    }

    private function markState(
        PayoutRequest $payoutRequest,
        User $reviewer,
        string $status,
        ?string $notes = null,
        $paidAt = null,
    ): PayoutRequest {
        $payoutRequest->forceFill([
            'status' => $status,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => $payoutRequest->reviewed_at ?? now(),
            'review_notes' => $notes,
            'paid_at' => $paidAt,
        ])->save();

        return $payoutRequest->refresh();
    }

    private function resolveProcessor(): PayoutProcessor
    {
        $mode = (string) config('payouts.mode', PayoutRequest::MODE_MANUAL);
        $provider = (string) config('payouts.provider', 'paymongo');

        if (
            $mode === PayoutRequest::MODE_PAYMONGO_TRANSFER
            && $provider === 'paymongo'
            && config('payouts.providers.paymongo.enabled')
        ) {
            return $this->payMongoProcessor;
        }

        return $this->manualProcessor;
    }
}
