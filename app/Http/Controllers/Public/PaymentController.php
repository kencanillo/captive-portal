<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\WifiSession;
use App\Services\PayMongoService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    use ApiResponse;

    public function create(Request $request, PayMongoService $payMongoService)
    {
        $validated = $request->validate([
            'session_id' => ['required', 'integer', Rule::exists('wifi_sessions', 'id')],
        ]);

        $session = WifiSession::query()->with('plan')->findOrFail($validated['session_id']);

        if ($session->payment_status !== WifiSession::STATUS_PENDING) {
            return $this->error('Session is not eligible for payment.', ['session_id' => ['Invalid state.']], 409);
        }

        $intent = $payMongoService->createPaymentIntent($session);

        return $this->success([
            'checkout_url' => $intent['checkout_url'],
            'payment_intent_id' => $intent['payment_intent_id'],
        ], 'Payment checkout initialized.', 201);
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
