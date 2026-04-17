<?php

namespace App\Services;

use App\Jobs\ReleaseWifiAccessJob;
use App\Models\Payment;
use App\Models\WifiSession;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PayMongoQrPhService
{
    private string $secretKey;

    private string $webhookSecret;

    private string $baseUrl;

    private int $qrExpirySeconds;

    private int $webhookToleranceSeconds;

    public function __construct()
    {
        $this->secretKey = (string) config('services.paymongo.secret_key');
        $this->webhookSecret = (string) config('services.paymongo.webhook_secret');
        $this->baseUrl = rtrim((string) config('services.paymongo.base_url', 'https://api.paymongo.com/v1'), '/');
        $this->qrExpirySeconds = (int) config('services.paymongo.qrph_expiry_seconds', 1800);
        $this->webhookToleranceSeconds = (int) config('services.paymongo.webhook_tolerance_seconds', 300);
    }

    public function createOrReusePayment(WifiSession $session): Payment
    {
        $session->loadMissing(['plan', 'client']);

        $existingOpenPayment = $session->payments()
            ->latest('id')
            ->whereIn('status', [
                Payment::STATUS_PENDING,
                Payment::STATUS_AWAITING_PAYMENT,
            ])
            ->first();

        if ($existingOpenPayment) {
            $existingOpenPayment = $this->ensurePaymentIsFresh($existingOpenPayment);

            if (in_array($existingOpenPayment->status, [
                Payment::STATUS_PENDING,
                Payment::STATUS_AWAITING_PAYMENT,
            ], true)) {
                Log::info('Reusing existing PayMongo QRPh payment attempt.', [
                    'payment_id' => $existingOpenPayment->id,
                    'wifi_session_id' => $session->id,
                    'paymongo_payment_intent_id' => $existingOpenPayment->paymongo_payment_intent_id,
                ]);

                return $existingOpenPayment->fresh();
            }
        }

        $latestPaidPayment = $session->payments()
            ->latest('id')
            ->where('status', Payment::STATUS_PAID)
            ->first();

        if ($latestPaidPayment && in_array($session->session_status, [
            WifiSession::SESSION_STATUS_PAID,
            WifiSession::SESSION_STATUS_ACTIVE,
            WifiSession::SESSION_STATUS_RELEASE_FAILED,
        ], true)) {
            return $latestPaidPayment;
        }

        return $this->createPayment($session);
    }

    public function ensurePaymentIsFresh(Payment $payment): Payment
    {
        if ($payment->status === Payment::STATUS_PAID) {
            return $payment;
        }

        if ($payment->qr_expires_at && $payment->qr_expires_at->isPast()) {
            $this->markPaymentAsExpired(
                $payment,
                null,
                ['reason' => 'local_expiry_check'],
                now()
            );
        }

        return $payment->fresh();
    }

    public function handleWebhook(string $payload, ?string $signatureHeader): void
    {
        Log::info('PayMongo webhook received.', [
            'signature_present' => (bool) $signatureHeader,
        ]);

        if (! $this->verifySignature($payload, $signatureHeader)) {
            throw new RuntimeException('Invalid PayMongo webhook signature.');
        }

        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $eventId = Arr::get($decoded, 'data.id');
        $eventType = Arr::get($decoded, 'data.attributes.type');
        $resource = Arr::get($decoded, 'data.attributes.data', []);
        $receivedAt = now();

        if (! in_array($eventType, ['payment.paid', 'payment.failed', 'qrph.expired'], true)) {
            Log::info('Ignoring unsupported PayMongo webhook event.', [
                'event_id' => $eventId,
                'event_type' => $eventType,
            ]);

            return;
        }

        $paymentIntentId = $this->extractPaymentIntentId($eventType, $resource);

        if (! $paymentIntentId) {
            throw new RuntimeException('Unable to resolve PayMongo payment intent id from webhook payload.');
        }

        $payment = Payment::query()
            ->with(['wifiSession.plan', 'wifiSession.client', 'wifiSession.site', 'wifiSession.accessPoint'])
            ->where('provider', Payment::PROVIDER_PAYMONGO)
            ->where('payment_flow', Payment::FLOW_QRPH)
            ->where('paymongo_payment_intent_id', $paymentIntentId)
            ->latest('id')
            ->firstOrFail();

        match ($eventType) {
            'payment.paid' => $this->handlePaidWebhook($payment, $eventId, $decoded, $resource, $receivedAt),
            'payment.failed' => $this->handleFailedWebhook($payment, $eventId, $decoded, $resource, $receivedAt),
            'qrph.expired' => $this->handleExpiredWebhook($payment, $eventId, $decoded, $receivedAt),
        };
    }

    public function recheckPayment(Payment $payment): Payment
    {
        $payment = $this->ensurePaymentIsFresh($payment->fresh(['wifiSession.plan', 'wifiSession.client']));

        if ($payment->isTerminal()) {
            return $payment;
        }

        Log::info('Manual PayMongo payment recheck requested.', [
            'payment_id' => $payment->id,
            'wifi_session_id' => $payment->wifi_session_id,
            'paymongo_payment_intent_id' => $payment->paymongo_payment_intent_id,
        ]);

        $response = $this->request(
            'get',
            "/payment_intents/{$payment->paymongo_payment_intent_id}"
        );

        $paymentIntent = $response['data'] ?? [];
        $paymentIntentAttributes = Arr::get($paymentIntent, 'attributes', []);
        $payMongoStatus = Arr::get($paymentIntentAttributes, 'status');
        $payments = Arr::get($paymentIntentAttributes, 'payments', []);
        $latestPayMongoPayment = is_array($payments) && $payments !== [] ? end($payments) : null;

        $payment->forceFill([
            'raw_response' => array_merge($payment->raw_response ?? [], [
                'last_recheck_payment_intent' => $response,
            ]),
        ])->save();

        if ($payMongoStatus === 'succeeded') {
            $payMongoPaymentData = is_array($latestPayMongoPayment) ? $latestPayMongoPayment : [];

            $this->markPaymentAsPaid(
                $payment,
                $payMongoPaymentData,
                "manual-recheck:{$payment->paymongo_payment_intent_id}",
                $response,
                now(),
                true
            );

            return $payment->fresh(['wifiSession']);
        }

        if ($payMongoStatus === 'awaiting_payment_method') {
            $failedMessage = Arr::get($paymentIntentAttributes, 'last_payment_error.message')
                ?? Arr::get($latestPayMongoPayment, 'attributes.failed_message');

            if ($payment->qr_expires_at && $payment->qr_expires_at->isPast()) {
                $this->markPaymentAsExpired(
                    $payment,
                    "manual-recheck-expired:{$payment->paymongo_payment_intent_id}",
                    $response,
                    now()
                );
            } elseif ($failedMessage) {
                $this->markPaymentAsFailed(
                    $payment,
                    $failedMessage,
                    $latestPayMongoPayment,
                    "manual-recheck-failed:{$payment->paymongo_payment_intent_id}",
                    $response,
                    now()
                );
            }
        } elseif (in_array($payMongoStatus, ['awaiting_next_action', 'processing'], true)) {
            $this->markPaymentAsAwaitingPayment($payment, $response);
        }

        Log::info('PayMongo payment recheck completed.', [
            'payment_id' => $payment->id,
            'local_payment_status' => $payment->fresh()->status,
            'paymongo_payment_intent_status' => $payMongoStatus,
        ]);

        return $payment->fresh(['wifiSession']);
    }

    private function createPayment(WifiSession $session): Payment
    {
        if (! $this->secretKey) {
            throw new RuntimeException('PayMongo secret key is not configured.');
        }

        $session->loadMissing(['plan', 'client']);

        $localReference = Str::upper(Str::random(8));
        $paymentIntentResponse = $this->request('post', '/payment_intents', [
            'data' => [
                'attributes' => [
                    'amount' => $this->toCentavos($session->amount_paid),
                    'currency' => 'PHP',
                    'capture_type' => 'automatic',
                    'payment_method_allowed' => ['qrph'],
                    'description' => "KapitWiFi {$session->plan->name}",
                    'metadata' => [
                        'wifi_session_id' => (string) $session->id,
                        'mac_address' => $session->mac_address,
                        'local_reference' => $localReference,
                    ],
                ],
            ],
        ]);

        $paymentIntentId = Arr::get($paymentIntentResponse, 'data.id');

        if (! $paymentIntentId) {
            throw new RuntimeException('PayMongo payment intent response is missing the id.');
        }

        $paymentMethodResponse = $this->request('post', '/payment_methods', [
            'data' => [
                'attributes' => [
                    'type' => 'qrph',
                    'billing' => [
                        'name' => $session->client?->name ?? "Session {$session->id}",
                        'email' => $this->resolveBillingEmail($session),
                        'phone' => $this->normalizePhoneNumber($session->client?->phone_number),
                    ],
                    'metadata' => [
                        'wifi_session_id' => (string) $session->id,
                        'local_reference' => $localReference,
                    ],
                    'expiry_seconds' => $this->qrExpirySeconds,
                ],
            ],
        ]);

        $paymentMethodId = Arr::get($paymentMethodResponse, 'data.id');

        if (! $paymentMethodId) {
            throw new RuntimeException('PayMongo payment method response is missing the id.');
        }

        $attachResponse = $this->request('post', "/payment_intents/{$paymentIntentId}/attach", [
            'data' => [
                'attributes' => [
                    'payment_method' => $paymentMethodId,
                ],
            ],
        ]);

        $qrImageUrl = Arr::get($attachResponse, 'data.attributes.next_action.code.image_url');
        $qrReference = Arr::get($attachResponse, 'data.attributes.next_action.code.id');
        $paymentStatus = $this->mapPayMongoIntentStatusToLocalStatus(
            Arr::get($attachResponse, 'data.attributes.status'),
            null
        );

        if (! $qrImageUrl) {
            throw new RuntimeException('PayMongo attach response did not include a QR image.');
        }

        $payment = DB::transaction(function () use (
            $session,
            $localReference,
            $paymentIntentId,
            $paymentMethodId,
            $qrReference,
            $qrImageUrl,
            $paymentStatus,
            $paymentIntentResponse,
            $paymentMethodResponse,
            $attachResponse
        ): Payment {
            $payment = Payment::query()->create([
                'wifi_session_id' => $session->id,
                'provider' => Payment::PROVIDER_PAYMONGO,
                'payment_flow' => Payment::FLOW_QRPH,
                'reference_id' => $localReference,
                'status' => $paymentStatus,
                'raw_response' => [
                    'payment_intent' => $paymentIntentResponse,
                    'payment_method' => $paymentMethodResponse,
                    'attach' => $attachResponse,
                ],
                'paymongo_payment_intent_id' => $paymentIntentId,
                'paymongo_payment_method_id' => $paymentMethodId,
                'qr_reference' => $qrReference,
                'qr_image_url' => $qrImageUrl,
                'qr_expires_at' => now()->addSeconds($this->qrExpirySeconds),
                'amount' => $session->amount_paid,
                'currency' => 'PHP',
            ]);

            $session->forceFill([
                'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
                'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
                'release_failure_reason' => null,
                'paymongo_payment_intent_id' => $paymentIntentId,
            ])->save();

            return $payment;
        });

        Log::info('Created PayMongo QRPh payment attempt.', [
            'payment_id' => $payment->id,
            'wifi_session_id' => $session->id,
            'paymongo_payment_intent_id' => $paymentIntentId,
            'paymongo_payment_method_id' => $paymentMethodId,
            'qr_reference' => $qrReference,
            'qr_expires_at' => $payment->qr_expires_at?->toIso8601String(),
        ]);

        return $payment->load(['wifiSession.plan', 'wifiSession.client']);
    }

    private function handlePaidWebhook(
        Payment $payment,
        ?string $eventId,
        array $payload,
        array $resource,
        Carbon $receivedAt
    ): void {
        if ($payment->status === Payment::STATUS_PAID
            && ($payment->webhook_last_event_id === $eventId || $payment->paymongo_payment_id === Arr::get($resource, 'id'))) {
            Log::info('Ignoring duplicate PayMongo payment.paid webhook.', [
                'payment_id' => $payment->id,
                'event_id' => $eventId,
                'paymongo_payment_id' => Arr::get($resource, 'id'),
            ]);

            return;
        }

        $this->markPaymentAsPaid($payment, $resource, $eventId, $payload, $receivedAt, true);
    }

    private function handleFailedWebhook(
        Payment $payment,
        ?string $eventId,
        array $payload,
        array $resource,
        Carbon $receivedAt
    ): void {
        if ($payment->status === Payment::STATUS_PAID) {
            Log::warning('Ignoring payment.failed webhook for already-paid payment.', [
                'payment_id' => $payment->id,
                'event_id' => $eventId,
            ]);

            return;
        }

        if ($payment->webhook_last_event_id === $eventId) {
            Log::info('Ignoring duplicate PayMongo payment.failed webhook.', [
                'payment_id' => $payment->id,
                'event_id' => $eventId,
            ]);

            return;
        }

        $this->markPaymentAsFailed(
            $payment,
            Arr::get($resource, 'attributes.failed_message', 'Payment failed in PayMongo.'),
            $resource,
            $eventId,
            $payload,
            $receivedAt
        );
    }

    private function handleExpiredWebhook(
        Payment $payment,
        ?string $eventId,
        array $payload,
        Carbon $receivedAt
    ): void {
        if ($payment->status === Payment::STATUS_PAID) {
            Log::warning('Ignoring qrph.expired webhook for already-paid payment.', [
                'payment_id' => $payment->id,
                'event_id' => $eventId,
            ]);

            return;
        }

        if ($payment->webhook_last_event_id === $eventId) {
            Log::info('Ignoring duplicate PayMongo qrph.expired webhook.', [
                'payment_id' => $payment->id,
                'event_id' => $eventId,
            ]);

            return;
        }

        $this->markPaymentAsExpired($payment, $eventId, $payload, $receivedAt);
    }

    private function markPaymentAsAwaitingPayment(Payment $payment, array $payload): void
    {
        $payment->forceFill([
            'status' => Payment::STATUS_AWAITING_PAYMENT,
            'raw_response' => array_merge($payment->raw_response ?? [], [
                'last_status_refresh' => $payload,
            ]),
        ])->save();

        $payment->wifiSession()->update([
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
        ]);
    }

    private function markPaymentAsPaid(
        Payment $payment,
        array $resource,
        ?string $eventId,
        array $payload,
        Carbon $receivedAt,
        bool $dispatchRelease
    ): void {
        $dispatch = false;

        DB::transaction(function () use ($payment, $resource, $eventId, $payload, $receivedAt, $dispatchRelease, &$dispatch): void {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->with(['wifiSession.plan', 'wifiSession.client', 'wifiSession.site', 'wifiSession.accessPoint'])
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($lockedPayment->status === Payment::STATUS_PAID
                && ($lockedPayment->webhook_last_event_id === $eventId || $lockedPayment->paymongo_payment_id === Arr::get($resource, 'id'))) {
                Log::info('Duplicate payment.paid webhook skipped during locked update.', [
                    'payment_id' => $lockedPayment->id,
                    'event_id' => $eventId,
                ]);

                return;
            }

            $paidAt = Carbon::createFromTimestamp((int) Arr::get($resource, 'attributes.paid_at', now()->timestamp));

            $lockedPayment->forceFill([
                'status' => Payment::STATUS_PAID,
                'paymongo_payment_id' => Arr::get($resource, 'id'),
                'reference_id' => Arr::get($resource, 'attributes.external_reference_number') ?: $lockedPayment->reference_id,
                'qr_reference' => Arr::get($resource, 'attributes.source.provider.code_id') ?: $lockedPayment->qr_reference,
                'paid_at' => $paidAt,
                'webhook_last_event_id' => $eventId,
                'webhook_last_payload' => $payload,
                'webhook_received_at' => $receivedAt,
                'failure_reason' => null,
                'raw_response' => array_merge($lockedPayment->raw_response ?? [], [
                    'last_paid_payload' => $payload,
                ]),
            ])->save();

            $session = $lockedPayment->wifiSession;
            $session->forceFill([
                'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
                'session_status' => $session->is_active
                    ? WifiSession::SESSION_STATUS_ACTIVE
                    : WifiSession::SESSION_STATUS_PAID,
                'release_failure_reason' => null,
                'amount_paid' => $lockedPayment->amount ?? $session->amount_paid,
                'paymongo_payment_intent_id' => $lockedPayment->paymongo_payment_intent_id,
            ])->save();

            Log::info('Marked payment as paid from PayMongo webhook.', [
                'payment_id' => $lockedPayment->id,
                'wifi_session_id' => $session->id,
                'event_id' => $eventId,
                'paymongo_payment_id' => $lockedPayment->paymongo_payment_id,
            ]);

            $dispatch = $dispatchRelease && $session->session_status !== WifiSession::SESSION_STATUS_ACTIVE;
        });

        if ($dispatch) {
            ReleaseWifiAccessJob::dispatch($payment->id)->afterCommit();
        }
    }

    private function markPaymentAsFailed(
        Payment $payment,
        string $failureReason,
        array $resource,
        ?string $eventId,
        array $payload,
        Carbon $receivedAt
    ): void {
        DB::transaction(function () use ($payment, $failureReason, $resource, $eventId, $payload, $receivedAt): void {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->with('wifiSession')
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($lockedPayment->status === Payment::STATUS_PAID) {
                return;
            }

            $lockedPayment->forceFill([
                'status' => Payment::STATUS_FAILED,
                'paymongo_payment_id' => Arr::get($resource, 'id') ?: $lockedPayment->paymongo_payment_id,
                'reference_id' => Arr::get($resource, 'attributes.external_reference_number') ?: $lockedPayment->reference_id,
                'webhook_last_event_id' => $eventId,
                'webhook_last_payload' => $payload,
                'webhook_received_at' => $receivedAt,
                'failure_reason' => $failureReason,
                'raw_response' => array_merge($lockedPayment->raw_response ?? [], [
                    'last_failed_payload' => $payload,
                ]),
            ])->save();

            $lockedPayment->wifiSession->forceFill([
                'payment_status' => WifiSession::PAYMENT_STATUS_FAILED,
                'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            ])->save();

            Log::warning('Marked payment as failed from PayMongo update.', [
                'payment_id' => $lockedPayment->id,
                'wifi_session_id' => $lockedPayment->wifi_session_id,
                'event_id' => $eventId,
                'failure_reason' => $failureReason,
            ]);
        });
    }

    private function markPaymentAsExpired(
        Payment $payment,
        ?string $eventId,
        array $payload,
        Carbon $receivedAt
    ): void {
        DB::transaction(function () use ($payment, $eventId, $payload, $receivedAt): void {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()
                ->with('wifiSession')
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($lockedPayment->status === Payment::STATUS_PAID) {
                return;
            }

            $lockedPayment->forceFill([
                'status' => Payment::STATUS_EXPIRED,
                'webhook_last_event_id' => $eventId ?: $lockedPayment->webhook_last_event_id,
                'webhook_last_payload' => $payload,
                'webhook_received_at' => $receivedAt,
                'failure_reason' => 'QR expired before payment was confirmed.',
                'raw_response' => array_merge($lockedPayment->raw_response ?? [], [
                    'last_expired_payload' => $payload,
                ]),
            ])->save();

            $lockedPayment->wifiSession->forceFill([
                'payment_status' => WifiSession::PAYMENT_STATUS_EXPIRED,
                'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            ])->save();

            Log::warning('Marked payment as expired.', [
                'payment_id' => $lockedPayment->id,
                'wifi_session_id' => $lockedPayment->wifi_session_id,
                'event_id' => $eventId,
            ]);
        });
    }

    private function mapPayMongoIntentStatusToLocalStatus(?string $payMongoStatus, ?Payment $payment): string
    {
        return match ($payMongoStatus) {
            'succeeded' => Payment::STATUS_PAID,
            'awaiting_next_action', 'processing' => Payment::STATUS_AWAITING_PAYMENT,
            'awaiting_payment_method' => $payment?->qr_expires_at?->isPast()
                ? Payment::STATUS_EXPIRED
                : Payment::STATUS_PENDING,
            'canceled' => Payment::STATUS_CANCELED,
            default => Payment::STATUS_PENDING,
        };
    }

    private function extractPaymentIntentId(string $eventType, array $resource): ?string
    {
        return match ($eventType) {
            'payment.paid', 'payment.failed' => Arr::get($resource, 'attributes.payment_intent_id'),
            'qrph.expired' => Arr::get($resource, 'attributes.payment_intent_id'),
            default => null,
        };
    }

    private function request(string $method, string $uri, array $payload = []): array
    {
        $response = Http::withBasicAuth($this->secretKey, '')
            ->acceptJson()
            ->asJson()
            ->send($method, "{$this->baseUrl}{$uri}", $payload === [] ? [] : ['json' => $payload]);

        return $this->decodeResponse($response);
    }

    private function decodeResponse(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException("PayMongo request failed with HTTP {$response->status()}: {$response->body()}");
        }

        return $response->json();
    }

    private function verifySignature(string $payload, ?string $signatureHeader): bool
    {
        if (! $this->webhookSecret || ! $signatureHeader) {
            return false;
        }

        $parts = collect(explode(',', $signatureHeader))
            ->mapWithKeys(function (string $segment): array {
                $pair = explode('=', trim($segment), 2);

                return count($pair) === 2 ? [$pair[0] => $pair[1]] : [];
            });

        $timestamp = $parts->get('t');
        $receivedSignature = $parts->get('te') ?? $parts->get('v1') ?? $parts->get('li');

        if (! $timestamp || ! $receivedSignature) {
            return false;
        }

        if (abs(now()->timestamp - (int) $timestamp) > $this->webhookToleranceSeconds) {
            Log::warning('Rejecting PayMongo webhook outside tolerance window.', [
                'timestamp' => $timestamp,
                'tolerance_seconds' => $this->webhookToleranceSeconds,
            ]);

            return false;
        }

        $computed = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);

        return hash_equals($computed, $receivedSignature);
    }

    private function resolveBillingEmail(WifiSession $session): string
    {
        $normalizedPhone = preg_replace('/\D+/', '', (string) $session->client?->phone_number);

        if ($normalizedPhone) {
            return "client{$normalizedPhone}@kapitwifi.local";
        }

        return "session{$session->id}@kapitwifi.local";
    }

    private function normalizePhoneNumber(?string $phoneNumber): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phoneNumber);

        if ($digits === '') {
            return '';
        }

        if (Str::startsWith($digits, '0')) {
            return '+63' . substr($digits, 1);
        }

        if (Str::startsWith($digits, '63')) {
            return '+' . $digits;
        }

        if (Str::startsWith($digits, '9')) {
            return '+63' . $digits;
        }

        return '+' . $digits;
    }

    private function toCentavos(string|float|int $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
