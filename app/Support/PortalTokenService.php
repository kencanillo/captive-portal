<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\WifiSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use JsonException;

class PortalTokenService
{
    private const SIGNATURE_LENGTH = 32;

    public function issuePortalContextToken(array $context): string
    {
        return $this->encode([
            'type' => 'portal_context',
            'context' => [
                'mac_address' => (string) Arr::get($context, 'mac_address'),
                'ap_mac' => Arr::get($context, 'ap_mac'),
                'ap_name' => Arr::get($context, 'ap_name'),
                'site_name' => Arr::get($context, 'site_name'),
                'site_identifier' => Arr::get($context, 'site_identifier'),
                'ssid_name' => Arr::get($context, 'ssid_name'),
                'radio_id' => Arr::get($context, 'radio_id'),
                'client_ip' => Arr::get($context, 'client_ip'),
            ],
            'exp' => CarbonImmutable::now()
                ->addMinutes((int) config('portal.context_token_lifetime_minutes', 10))
                ->timestamp,
        ]);
    }

    public function resolvePortalContext(string $token): array
    {
        $payload = $this->decode($token, 'portal_context');
        $context = $payload['context'] ?? [];

        if (! is_array($context) || blank(Arr::get($context, 'mac_address'))) {
            throw new InvalidArgumentException('Portal context token is invalid.');
        }

        return $context;
    }

    public function issueSessionToken(WifiSession $session): string
    {
        return $this->encode([
            'type' => 'wifi_session',
            'id' => $session->getKey(),
            'exp' => CarbonImmutable::now()
                ->addMinutes((int) config('portal.session_token_lifetime_minutes', 120))
                ->timestamp,
        ]);
    }

    public function resolveSessionToken(string $token): WifiSession
    {
        $payload = $this->decode($token, 'wifi_session');
        $id = (int) ($payload['id'] ?? 0);

        if ($id < 1) {
            throw new InvalidArgumentException('Session token is invalid.');
        }

        return WifiSession::query()->findOrFail($id);
    }

    public function issuePaymentToken(Payment $payment): string
    {
        return $this->encode([
            'type' => 'payment',
            'id' => $payment->getKey(),
            'exp' => CarbonImmutable::now()
                ->addMinutes((int) config('portal.payment_token_lifetime_minutes', 180))
                ->timestamp,
        ]);
    }

    public function resolvePaymentToken(string $token): Payment
    {
        $payload = $this->decode($token, 'payment');
        $id = (int) ($payload['id'] ?? 0);

        if ($id < 1) {
            throw new InvalidArgumentException('Payment token is invalid.');
        }

        return Payment::query()->findOrFail($id);
    }

    private function encode(array $payload): string
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to encode portal token payload.', 0, $exception);
        }

        $signature = hash_hmac('sha256', $json, $this->signingKey(), true);

        return $this->base64UrlEncode($signature.$json);
    }

    private function decode(string $token, string $expectedType): array
    {
        $decoded = $this->base64UrlDecode($token);

        if (strlen($decoded) <= self::SIGNATURE_LENGTH) {
            throw new InvalidArgumentException('Portal token is malformed.');
        }

        $signature = substr($decoded, 0, self::SIGNATURE_LENGTH);
        $json = substr($decoded, self::SIGNATURE_LENGTH);
        $expectedSignature = hash_hmac('sha256', $json, $this->signingKey(), true);

        if (! hash_equals($expectedSignature, $signature)) {
            throw new InvalidArgumentException('Portal token signature is invalid.');
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Portal token payload is invalid.', 0, $exception);
        }

        if (($payload['type'] ?? null) !== $expectedType) {
            throw new InvalidArgumentException('Portal token type mismatch.');
        }

        if ((int) ($payload['exp'] ?? 0) < CarbonImmutable::now()->timestamp) {
            throw new InvalidArgumentException('Portal token expired.');
        }

        return $payload;
    }

    private function signingKey(): string
    {
        $appKey = (string) Config::get('app.key', '');

        if (str_starts_with($appKey, 'base64:')) {
            return base64_decode(substr($appKey, 7), true) ?: substr($appKey, 7);
        }

        return $appKey;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            $padding = strlen($value) % 4;

            if ($padding !== 0) {
                $value .= str_repeat('=', 4 - $padding);
            }

            $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        }

        if ($decoded === false) {
            throw new InvalidArgumentException('Portal token encoding is invalid.');
        }

        return $decoded;
    }
}
