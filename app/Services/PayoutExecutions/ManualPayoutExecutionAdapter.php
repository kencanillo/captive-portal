<?php

namespace App\Services\PayoutExecutions;

use App\Models\PayoutExecutionAttempt;
use App\Models\PayoutRequest;

class ManualPayoutExecutionAdapter implements PayoutExecutionAdapter
{
    public function name(): string
    {
        return 'manual';
    }

    public function readiness(): array
    {
        return [
            'provider' => $this->name(),
            'ready' => true,
            'summary' => 'Manual payout execution stub is available.',
            'blocking_reason' => null,
            'details' => [],
        ];
    }

    public function destinationPreflight(PayoutRequest $payoutRequest): array
    {
        return [
            'provider' => $this->name(),
            'ready' => true,
            'summary' => 'Manual payout execution does not apply provider-side destination checks.',
            'blocking_reason' => null,
            'details' => [],
        ];
    }

    public function dispatch(PayoutRequest $payoutRequest, PayoutExecutionAttempt $attempt): array
    {
        return [
            'provider_name' => $this->name(),
            'execution_state' => PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
            'external_reference' => null,
            'provider_request_metadata' => [
                'destination_type' => $payoutRequest->destination_type,
                'destination_account_name' => $payoutRequest->destination_account_name,
                'destination_account_reference' => $payoutRequest->destination_account_reference,
                'amount' => round((float) $payoutRequest->amount, 2),
                'currency' => $payoutRequest->currency,
                'execution_reference' => $attempt->execution_reference,
                'idempotency_key' => $attempt->idempotency_key,
            ],
            'provider_response_metadata' => [
                'message' => 'Manual payout execution stub recorded. No external transfer was sent.',
            ],
            'last_error' => null,
            'completed_at' => null,
        ];
    }

    public function reconcile(PayoutRequest $payoutRequest, PayoutExecutionAttempt $attempt, ?array $providerPayload = null, string $source = 'poll'): array
    {
        return [
            'provider_name' => $this->name(),
            'provider_state' => 'manual_followup_required',
            'execution_state' => $attempt->execution_state,
            'external_reference' => $attempt->external_reference,
            'provider_response_metadata' => [
                'message' => 'Manual execution adapter reconciliation performed. No external provider was contacted.',
                'source' => $source,
                'provider_payload' => $providerPayload,
            ],
            'last_error' => $attempt->last_error,
            'completed_at' => $attempt->completed_at,
        ];
    }

    public function verifyCallback(string $payload, ?string $signatureHeader): bool
    {
        return false;
    }
}
