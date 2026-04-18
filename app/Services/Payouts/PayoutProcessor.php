<?php

namespace App\Services\Payouts;

use App\Models\PayoutRequest;

interface PayoutProcessor
{
    public function mode(): string;

    public function process(PayoutRequest $payoutRequest): array;
}
