<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\WifiSession;
use App\Services\PayMongoQrPhService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PaymentController extends Controller
{
    use ApiResponse;

    public function create(Request $request, PayMongoQrPhService $payMongoQrPhService)
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer', Rule::exists('wifi_sessions', 'id')],
        ]);

        $session = WifiSession::query()
            ->with(['plan', 'client'])
            ->findOrFail($validated['session_id']);

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
                'payment_id' => $payment->id,
                'payment_url' => route('payments.show', $payment),
                'payment_intent_id' => $payment->paymongo_payment_intent_id,
                'payment_status' => $payment->payment_status,
                'qr_expires_at' => $payment->qr_expires_at?->toIso8601String(),
            ],
        ], $payment->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Payment $payment, PayMongoQrPhService $payMongoQrPhService): Response
    {
        $payment = $payMongoQrPhService->ensurePaymentIsFresh(
            $payment->load(['wifiSession.plan', 'wifiSession.client', 'wifiSession.site', 'wifiSession.accessPoint'])
        );

        return Inertia::render('Public/PaymentStatus', [
            'payment' => [
                'id' => $payment->id,
                'payment_status' => $payment->payment_status,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'qr_image_url' => $payment->qr_image_url,
                'qr_reference' => $payment->qr_reference,
                'qr_expires_at' => $payment->qr_expires_at?->toIso8601String(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ],
            'session' => [
                'id' => $payment->wifiSession->id,
                'payment_status' => $payment->wifiSession->payment_status,
                'session_status' => $payment->wifiSession->session_status,
                'release_failure_reason' => $payment->wifiSession->release_failure_reason,
            ],
            'plan' => [
                'id' => $payment->wifiSession->plan->id,
                'name' => $payment->wifiSession->plan->name,
                'duration_minutes' => $payment->wifiSession->plan->duration_minutes,
            ],
            'statusEndpoint' => route('payments.status.show', $payment),
            'recheckEndpoint' => route('payments.recheck.store', $payment),
            'createPaymentEndpoint' => route('api.create-payment'),
            'backToPlansUrl' => route('portal.index'),
        ]);
    }

    public function success(Request $request)
    {
        $session = WifiSession::query()->findOrFail($request->integer('session'));
        $payment = $session->payments()->latest('id')->first();

        if ($payment) {
            return Inertia::location(route('payments.show', $payment));
        }

        return Inertia::location(route('portal.index'));
    }

    public function failed(Request $request)
    {
        $session = WifiSession::query()->findOrFail($request->integer('session'));
        $payment = $session->payments()->latest('id')->first();

        if ($payment) {
            return Inertia::location(route('payments.show', $payment));
        }

        return Inertia::location(route('portal.index'));
    }
}
