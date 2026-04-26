<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PayMongoSignatureVerifier
{
    public function verify(string $payload, ?string $signatureHeader, string $secret, int $toleranceSeconds = 300): bool
    {
        if ($secret === '' || $signatureHeader === null || trim($signatureHeader) === '') {
            return false;
        }

        $parts = collect(explode(',', $signatureHeader))
            ->mapWithKeys(function (string $segment): array {
                $pair = explode('=', trim($segment), 2);

                return count($pair) === 2 ? [$pair[0] => $pair[1]] : [];
            });

        $timestamp = $parts->get('t');
        $receivedSignature = $this->resolveReceivedSignature($parts);

        if (! $timestamp || ! $receivedSignature) {
            return false;
        }

        if (abs(now()->timestamp - (int) $timestamp) > $toleranceSeconds) {
            Log::warning('Rejecting PayMongo callback outside tolerance window.', [
                'timestamp' => $timestamp,
                'tolerance_seconds' => $toleranceSeconds,
            ]);

            return false;
        }

        $computed = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return hash_equals($computed, $receivedSignature);
    }

    private function resolveReceivedSignature(Collection $parts): ?string
    {
        foreach (['te', 'v1', 'li'] as $key) {
            $value = $parts->get($key);

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
