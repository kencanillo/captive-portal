<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PayMongoQrPhService;
use App\Support\ApiResponse;

class PaymentRecheckController extends Controller
{
    use ApiResponse;

    public function __invoke(Payment $payment, PayMongoQrPhService $payMongoQrPhService)
    {
        $payment = $payMongoQrPhService->recheckPayment($payment);

        return $this->success([
            'payment_id' => $payment->id,
            'payment_status' => $payment->payment_status,
            'wifi_session_status' => $payment->wifiSession->session_status,
            'release_failure_reason' => $payment->wifiSession->release_failure_reason,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ], 'Payment recheck completed.');
    }
}
