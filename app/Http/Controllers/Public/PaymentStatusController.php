<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\WifiSession;
use App\Services\PayMongoQrPhService;
use App\Support\PortalTokenService;
use App\Support\ApiResponse;
use InvalidArgumentException;

class PaymentStatusController extends Controller
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

        $payment = $payMongoQrPhService->ensurePaymentIsFresh(
            $payment->load('wifiSession')
        );

        $session = $payment->wifiSession;
        [$shouldContinuePolling, $nextStep, $humanMessage] = $this->resolveState($payment, $session);

        return $this->success([
            'payment_status' => $payment->payment_status,
            'wifi_session_status' => $session->session_status,
            'release_failure_reason' => $session->release_failure_reason,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'qr_expires_at' => $payment->qr_expires_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'session_start_time' => $session->start_time?->toIso8601String(),
            'session_end_time' => $session->end_time?->toIso8601String(),
            'should_continue_polling' => $shouldContinuePolling,
            'next_step' => $nextStep,
            'human_message' => $humanMessage,
        ]);
    }

    private function resolveState(Payment $payment, WifiSession $session): array
    {
        if (in_array($payment->payment_status, [Payment::STATUS_PENDING, Payment::STATUS_AWAITING_PAYMENT], true)) {
            return [true, 'keep_waiting', 'Waiting for payment confirmation.'];
        }

        if ($payment->payment_status === Payment::STATUS_PAID) {
            if ($session->session_status === WifiSession::SESSION_STATUS_ACTIVE) {
                return [false, 'access_enabled', 'Your internet access is now enabled.'];
            }

            if ($session->session_status === WifiSession::SESSION_STATUS_RELEASE_FAILED) {
                return [false, 'access_failed', 'Payment received, but internet access could not be enabled.'];
            }

            return [true, 'enabling_access', 'Payment received. Preparing your internet access.'];
        }

        if ($payment->payment_status === Payment::STATUS_EXPIRED) {
            return [false, 'regenerate_qr', 'This QR has expired. Generate a new one to continue.'];
        }

        if ($payment->payment_status === Payment::STATUS_FAILED) {
            return [false, 'regenerate_qr', 'Payment was not confirmed. Please try again.'];
        }

        if ($payment->payment_status === Payment::STATUS_CANCELED) {
            return [false, 'regenerate_qr', 'Payment was canceled. Generate a new QR to continue.'];
        }

        return [false, 'contact_support', 'Payment status is unknown. Contact support if this persists.'];
    }
}
