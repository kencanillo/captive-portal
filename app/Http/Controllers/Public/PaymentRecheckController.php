<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\PayMongoQrPhService;
use App\Support\PortalTokenService;
use App\Support\ApiResponse;
use InvalidArgumentException;

class PaymentRecheckController extends Controller
{
    use ApiResponse;

    public function __invoke(
        string $paymentToken,
        PayMongoQrPhService $payMongoQrPhService,
        PortalTokenService $portalTokenService
    )
    {
        try {
            $payment = $portalTokenService->resolvePaymentToken($paymentToken);
        } catch (InvalidArgumentException $exception) {
            abort(404);
        }

        $payment = $payMongoQrPhService->recheckPayment($payment);

        return $this->success([
            'payment_status' => $payment->payment_status,
            'wifi_session_status' => $payment->wifiSession->session_status,
            'release_failure_reason' => $payment->wifiSession->release_failure_reason,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ], 'Payment recheck completed.');
    }
}
