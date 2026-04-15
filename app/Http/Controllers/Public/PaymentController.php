<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\WifiSession;
use App\Services\PayMongoService;
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

    public function create(Request $request, PayMongoService $payMongoService)
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer', Rule::exists('wifi_sessions', 'id')],
        ]);

        $session = WifiSession::query()->with(['plan', 'client'])->findOrFail($validated['session_id']);

        if ($session->payment_status !== WifiSession::STATUS_PENDING) {
            return $this->error('Session is not eligible for payment.', ['session_id' => ['Invalid state.']], 409);
        }

        try {
            $intent = $payMongoService->createPaymentIntent($session);
        } catch (Throwable $exception) {
            Log::error('PayMongo checkout creation failed', [
                'session_id' => $session->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->error('Payment checkout initialization failed.', [
                'paymongo' => [$exception->getMessage()],
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment checkout initialized.',
            'data' => [
                'checkout_url' => $intent['checkout_url'],
                'payment_intent_id' => $intent['payment_intent_id'],
            ],
        ], 201);
    }

    public function success(Request $request): Response
    {
        $session = WifiSession::query()->findOrFail($request->integer('session'));

        return Inertia::render('Public/PaymentStatus', [
            'status' => $session->payment_status === WifiSession::STATUS_PAID ? 'success' : 'pending',
            'session' => $session,
        ]);
    }

    public function failed(Request $request): Response
    {
        $session = WifiSession::query()->findOrFail($request->integer('session'));

        if ($session->payment_status === WifiSession::STATUS_PENDING) {
            $session->update(['payment_status' => WifiSession::STATUS_FAILED]);
        }

        return Inertia::render('Public/PaymentStatus', [
            'status' => 'failed',
            'session' => $session,
        ]);
    }
}
