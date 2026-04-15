<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\WifiSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PayMongoService
{
    private string $secretKey;
    private string $webhookSecret;
    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = (string) config('services.paymongo.secret_key');
        $this->webhookSecret = (string) config('services.paymongo.webhook_secret');
        $this->baseUrl = rtrim((string) config('services.paymongo.base_url', 'https://api.paymongo.com/v1'), '/');
    }

    /**
     * Creates a PayMongo Checkout Session for QRPH and stores the reference on wifi_sessions.
     */
    public function createPaymentIntent(WifiSession $session): array
    {
        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'currency' => 'PHP',
                        'amount' => (int) round(((float) $session->amount_paid) * 100),
                        'description' => "KapitWiFi {$session->plan->name}",
                        'name' => $session->plan->name,
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => ['qrph'],
                    'success_url' => route('payment.success', ['session' => $session->id]),
                    'cancel_url' => route('payment.failed', ['session' => $session->id]),
                    'description' => "KapitWiFi session {$session->id}",
                    'metadata' => [
                        'wifi_session_id' => $session->id,
                        'mac_address' => $session->mac_address,
                    ],
                ],
            ],
        ];

        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->post("{$this->baseUrl}/checkout_sessions", $payload)
            ->throw()
            ->json();

        $checkoutData = Arr::get($response, 'data', []);
        $intentId = Arr::get($checkoutData, 'id');
        $checkoutUrl = Arr::get($checkoutData, 'attributes.checkout_url');

        if (! $intentId || ! $checkoutUrl) {
            throw new \RuntimeException('PayMongo checkout session response is missing required fields.');
        }

        $session->update(['paymongo_payment_intent_id' => $intentId]);

        Payment::create([
            'wifi_session_id' => $session->id,
            'provider' => 'paymongo',
            'reference_id' => $intentId,
            'status' => WifiSession::STATUS_PENDING,
            'raw_response' => $response,
        ]);

        return [
            'payment_intent_id' => $intentId,
            'checkout_url' => $checkoutUrl,
            'raw' => $response,
        ];
    }

    public function handleWebhook(string $payload, ?string $signatureHeader): void
    {
        Log::channel('daily')->info('PayMongo webhook payload received', ['payload' => $payload]);

        if (! $this->verifySignature($payload, $signatureHeader)) {
            throw new \RuntimeException('Invalid PayMongo webhook signature.');
        }

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        $eventType = Arr::get($decoded, 'data.attributes.type');
        $resource = Arr::get($decoded, 'data.attributes.data');

        if (! in_array($eventType, ['checkout_session.payment.paid', 'payment.paid'], true)) {
            return;
        }

        $checkoutSessionId = Arr::get($resource, 'id');
        $metadataSessionId = Arr::get($resource, 'attributes.metadata.wifi_session_id');

        $session = WifiSession::query()
            ->when($metadataSessionId, fn ($q) => $q->whereKey($metadataSessionId), fn ($q) => $q->where('paymongo_payment_intent_id', $checkoutSessionId))
            ->firstOrFail();

        DB::transaction(function () use ($session, $checkoutSessionId, $decoded) {
            if ($session->payment_status !== WifiSession::STATUS_PAID) {
                $session->update([
                    'payment_status' => WifiSession::STATUS_PAID,
                    'amount_paid' => $session->plan->price,
                ]);
            }

            Payment::updateOrCreate(
                [
                    'wifi_session_id' => $session->id,
                    'reference_id' => $checkoutSessionId ?? Str::uuid()->toString(),
                ],
                [
                    'provider' => 'paymongo',
                    'status' => WifiSession::STATUS_PAID,
                    'raw_response' => $decoded,
                ]
            );

            if (! $session->is_active) {
                app(WifiSessionService::class)->activateSession($session->fresh('plan'));
            }
        });
    }

    private function verifySignature(string $payload, ?string $signatureHeader): bool
    {
        if (! $this->webhookSecret) {
            return false;
        }

        if (! $signatureHeader) {
            return false;
        }

        $parts = collect(explode(',', $signatureHeader))
            ->mapWithKeys(function (string $segment) {
                $pair = explode('=', trim($segment), 2);
                return count($pair) === 2 ? [$pair[0] => $pair[1]] : [];
            });

        $timestamp = $parts->get('t');
        $receivedSignature = $parts->get('v1') ?? $parts->get('te') ?? $parts->get('li');

        if (! $timestamp || ! $receivedSignature) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $computed = hash_hmac('sha256', $signedPayload, $this->webhookSecret);

        return hash_equals($computed, $receivedSignature);
    }
}
