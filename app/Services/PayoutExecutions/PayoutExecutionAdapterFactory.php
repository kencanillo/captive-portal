<?php

namespace App\Services\PayoutExecutions;

use RuntimeException;

class PayoutExecutionAdapterFactory
{
    public function __construct(
        private readonly ManualPayoutExecutionAdapter $manualAdapter,
        private readonly PayMongoPayoutExecutionAdapter $payMongoAdapter,
    ) {
    }

    public function make(?string $provider = null): PayoutExecutionAdapter
    {
        $provider = $provider ?: (string) config('payouts.execution_provider', 'manual');

        return match ($provider) {
            'manual' => $this->manualAdapter,
            'paymongo' => $this->payMongoAdapter,
            default => throw new RuntimeException('Unsupported payout execution provider configured.'),
        };
    }
}
