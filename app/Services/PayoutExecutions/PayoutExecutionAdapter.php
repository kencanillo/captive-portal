<?php

namespace App\Services\PayoutExecutions;

use App\Models\PayoutExecutionAttempt;
use App\Models\PayoutRequest;

interface PayoutExecutionAdapter
{
    public function name(): string;

    public function readiness(): array;

    public function destinationPreflight(PayoutRequest $payoutRequest): array;

    public function dispatch(PayoutRequest $payoutRequest, PayoutExecutionAttempt $attempt): array;

    public function reconcile(PayoutRequest $payoutRequest, PayoutExecutionAttempt $attempt, ?array $providerPayload = null, string $source = 'poll'): array;

    public function verifyCallback(string $payload, ?string $signatureHeader): bool;
}
