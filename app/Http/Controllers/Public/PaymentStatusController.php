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
            'release_status' => $session->release_status,
            'release_attempt_count' => $session->release_attempt_count,
            'controller_state_uncertain' => $session->controller_state_uncertain,
            'last_release_error' => $session->last_release_error,
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
            if ($session->release_status === WifiSession::RELEASE_STATUS_SUCCEEDED
                || in_array($session->session_status, [
                    WifiSession::SESSION_STATUS_ACTIVE,
                    WifiSession::SESSION_STATUS_MERGED,
                ], true)) {
                return [false, 'access_enabled', 'Your internet access is now enabled.'];
            }

            if ($session->release_status === WifiSession::RELEASE_STATUS_UNCERTAIN) {
                return [false, 'contact_support', 'Payment was received, but controller state is uncertain. Please contact support so internet access can be recovered.'];
            }

            if ($session->release_status === WifiSession::RELEASE_STATUS_MANUAL_REQUIRED) {
                return [false, 'contact_support', 'Payment was received, but this session now requires manual support follow-up.'];
            }

            if ($session->release_status === WifiSession::RELEASE_STATUS_FAILED
                || $session->session_status === WifiSession::SESSION_STATUS_RELEASE_FAILED) {
                return [false, 'access_failed', 'Payment received, but internet access could not be enabled yet. Support can retry access activation.'];
            }

            if ($session->release_status === WifiSession::RELEASE_STATUS_IN_PROGRESS) {
                return [true, 'enabling_access', 'Payment received. Omada authorization is in progress.'];
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
