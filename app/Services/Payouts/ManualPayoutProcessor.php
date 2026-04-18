<?php

namespace App\Services\Payouts;

use App\Models\PayoutRequest;

class ManualPayoutProcessor implements PayoutProcessor
{
    public function mode(): string
    {
        return PayoutRequest::MODE_MANUAL;
    }

    public function process(PayoutRequest $payoutRequest): array
    {
        return [
            'processing_mode' => $this->mode(),
            'provider' => (string) config('payouts.provider', 'manual'),
            'provider_status' => 'manual_review_required',
            'provider_response' => [
                'message' => 'Manual payout handling is required.',
            ],
        ];
    }
}
