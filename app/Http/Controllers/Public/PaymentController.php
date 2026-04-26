<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PayMongoQrPhService;
use App\Support\PortalTokenService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

class PaymentController extends Controller
{
    use ApiResponse;

    public function create(
        Request $request,
        PayMongoQrPhService $payMongoQrPhService,
        PortalTokenService $portalTokenService
    )
    {
        $validated = $request->validate([
            'session_token' => ['required', 'string'],
        ]);

        try {
            $session = $portalTokenService->resolveSessionToken($validated['session_token'])
                ->load(['plan', 'client']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'session_token' => ['The payment session is invalid or expired. Select your plan again.'],
            ]);
        }

        try {
            $payment = $payMongoQrPhService->createOrReusePayment($session);
        } catch (Throwable $exception) {
            Log::error('PayMongo QRPh payment creation failed', [
                'session_id' => $session->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->error('Payment initialization failed.', [
                'paymongo' => [$exception->getMessage()],
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => $payment->wasRecentlyCreated
                ? 'QRPh payment initialized.'
                : 'Existing QRPh payment restored.',
            'data' => [
                'payment_url' => route('payments.show', [
                    'paymentToken' => $portalTokenService->issuePaymentToken($payment),
                ]),
                'payment_intent_id' => $payment->paymongo_payment_intent_id,
                'payment_status' => $payment->payment_status,
                'qr_expires_at' => $payment->qr_expires_at?->toIso8601String(),
            ],
        ], $payment->wasRecentlyCreated ? 201 : 200);
    }

    public function show(
        string $paymentToken,
        PayMongoQrPhService $payMongoQrPhService,
        PortalTokenService $portalTokenService
    ): Response
    {
        try {
            $payment = $portalTokenService->resolvePaymentToken($paymentToken);
        } catch (InvalidArgumentException $exception) {
            abort(404);
        }

        $payment = $payMongoQrPhService->ensurePaymentIsFresh(
            $payment->load(['wifiSession.plan', 'wifiSession.client', 'wifiSession.site', 'wifiSession.accessPoint'])
        );

        return Inertia::render('Public/PaymentStatus', [
            'payment' => [
                'payment_status' => $payment->payment_status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'qr_image_url' => $payment->qr_image_url,
                'qr_reference' => $payment->qr_reference,
                'qr_expires_at' => $payment->qr_expires_at?->toIso8601String(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
            'session' => [
                'payment_status' => $payment->wifiSession->payment_status,
                'session_status' => $payment->wifiSession->session_status,
                'release_status' => $payment->wifiSession->release_status,
                'release_attempt_count' => $payment->wifiSession->release_attempt_count,
                'controller_state_uncertain' => $payment->wifiSession->controller_state_uncertain,
                'last_release_error' => $payment->wifiSession->last_release_error,
                'release_failure_reason' => $payment->wifiSession->release_failure_reason,
                'start_time' => $payment->wifiSession->start_time?->toIso8601String(),
                'end_time' => $payment->wifiSession->end_time?->toIso8601String(),
            ],
            'plan' => [
                'id' => $payment->wifiSession->plan->id,
                'name' => $payment->wifiSession->plan->name,
                'duration_minutes' => $payment->wifiSession->plan->duration_minutes,
            ],
            'statusEndpoint' => route('payments.status.show', [
                'paymentToken' => $portalTokenService->issuePaymentToken($payment),
            ]),
            'recheckEndpoint' => route('payments.recheck.store', [
                'paymentToken' => $portalTokenService->issuePaymentToken($payment),
            ]),
            'sessionToken' => $portalTokenService->issueSessionToken($payment->wifiSession),
            'createPaymentEndpoint' => route('api.create-payment'),
            'qrDownloadEndpoint' => route('payments.qr.download', [
                'paymentToken' => $portalTokenService->issuePaymentToken($payment),
            ]),
            'backToPlansUrl' => route('portal.index'),
        ]);
    }

    public function success(Request $request, PortalTokenService $portalTokenService)
    {
        try {
            $session = $portalTokenService->resolveSessionToken($request->string('session_token')->toString());
        } catch (InvalidArgumentException $exception) {
            abort(404);
        }

        $payment = $session->payments()->latest('id')->first();

        if ($payment) {
            return Inertia::location(route('payments.show', [
                'paymentToken' => $portalTokenService->issuePaymentToken($payment),
            ]));
        }

        return Inertia::location(route('portal.index'));
    }

    public function failed(Request $request, PortalTokenService $portalTokenService)
    {
        try {
            $session = $portalTokenService->resolveSessionToken($request->string('session_token')->toString());
        } catch (InvalidArgumentException $exception) {
            abort(404);
        }

        $payment = $session->payments()->latest('id')->first();

        if ($payment) {
            return Inertia::location(route('payments.show', [
                'paymentToken' => $portalTokenService->issuePaymentToken($payment),
            ]));
        }

        return Inertia::location(route('portal.index'));
    }
}
