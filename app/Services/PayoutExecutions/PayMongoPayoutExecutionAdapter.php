<?php

namespace App\Services\PayoutExecutions;

use App\Models\PayoutExecutionAttempt;
use App\Models\PayoutRequest;
use App\Support\PayMongoSignatureVerifier;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PayMongoPayoutExecutionAdapter implements PayoutExecutionAdapter
{
    private string $secretKey;

    private string $callbackSecret;

    private string $baseUrl;

    private int $connectTimeoutSeconds;

    private int $timeoutSeconds;

    public function __construct(
        private readonly PayMongoSignatureVerifier $signatureVerifier,
    ) {
        $this->secretKey = (string) config('services.paymongo.secret_key');
        $this->callbackSecret = (string) config('services.paymongo.payout_webhook_secret', config('services.paymongo.webhook_secret', ''));
        $this->baseUrl = rtrim((string) config('services.paymongo.base_url', 'https://api.paymongo.com/v1'), '/');
        $this->connectTimeoutSeconds = max(1, (int) config('services.paymongo.connect_timeout_seconds', 2));
        $this->timeoutSeconds = max(1, (int) config('services.paymongo.timeout_seconds', 4));
    }

    public function name(): string
    {
        return 'paymongo';
    }

    public function readiness(): array
    {
        if (! ((bool) config('payouts.providers.paymongo.enabled') || (bool) config('services.paymongo.payouts_enabled'))) {
            return $this->blockedReadiness('PayMongo payout execution is disabled.');
        }

        if (trim($this->secretKey) === '') {
            return $this->blockedReadiness('PayMongo payout execution is blocked because the secret key is missing.');
        }

        if ($this->resolveWalletIdValue() === '') {
            return $this->blockedReadiness('PayMongo payout execution is blocked because the payout wallet ID is missing.');
        }

        if (trim($this->callbackSecret) === '') {
            return $this->blockedReadiness('PayMongo payout execution is blocked because the payout webhook secret is missing.');
        }

        $callbackBaseUrl = $this->resolveCallbackBaseUrl();

        if ($callbackBaseUrl === '') {
            return $this->blockedReadiness('PayMongo payout execution is blocked because the payout callback base URL is missing.');
        }

        if (! $this->isPublicCallbackBaseUrl($callbackBaseUrl)) {
            return $this->blockedReadiness('PayMongo payout execution is blocked because the payout callback base URL is not publicly reachable.');
        }

        $mode = $this->resolveMode();
        $liveExecutionEnabled = (bool) config('payouts.providers.paymongo.live_execution_enabled', false);

        if ($mode === 'live' && ! $liveExecutionEnabled) {
            return $this->blockedReadiness('PayMongo payout execution is blocked because live rollout is disabled for this environment.', [
                'callback_base_url' => $callbackBaseUrl,
                'wallet_id' => $this->resolveWalletIdValue(),
                'mode' => $mode,
                'live_execution_enabled' => $liveExecutionEnabled,
            ]);
        }

        return [
            'provider' => $this->name(),
            'ready' => true,
            'summary' => 'PayMongo payout configuration is complete enough for dispatch and callback verification.',
            'blocking_reason' => null,
            'details' => [
                'callback_base_url' => $callbackBaseUrl,
                'wallet_id' => $this->resolveWalletIdValue(),
                'mode' => $mode,
                'live_execution_enabled' => $liveExecutionEnabled,
            ],
        ];
    }

    public function destinationPreflight(PayoutRequest $payoutRequest): array
    {
        $destinationType = strtolower(trim((string) $payoutRequest->destination_type));
        $accountName = trim((string) $payoutRequest->destination_account_name);
        $accountReference = trim((string) $payoutRequest->destination_account_reference);
        $provider = strtolower(trim((string) Arr::get($payoutRequest->destination_snapshot ?? [], 'provider', '')));
        $bic = strtoupper(trim((string) Arr::get($payoutRequest->destination_snapshot ?? [], 'bic', '')));
        $normalizedReference = preg_replace('/[^A-Za-z0-9]/', '', $accountReference) ?? '';

        if (! in_array($destinationType, ['bank', 'paymongo_wallet'], true)) {
            return $this->blockedDestination('PayMongo payout execution currently supports only bank and PayMongo wallet destinations.');
        }

        if (mb_strlen($accountName) < 3) {
            return $this->blockedDestination('PayMongo payout execution is blocked because the destination account name is incomplete.');
        }

        if ($normalizedReference === '' || mb_strlen($normalizedReference) < 6 || mb_strlen($normalizedReference) > 34) {
            return $this->blockedDestination('PayMongo payout execution is blocked because the destination account reference is invalid.');
        }

        if ($destinationType === 'bank') {
            if (! in_array($provider, ['instapay', 'pesonet'], true)) {
                return $this->blockedDestination('PayMongo payout execution is blocked because the bank transfer provider must be Instapay or Pesonet.');
            }

            if ($bic === '' || ! preg_match('/^[A-Z0-9]{4,15}$/', $bic)) {
                return $this->blockedDestination('PayMongo payout execution is blocked because the bank code is missing or malformed.');
            }
        }

        if ($destinationType === 'paymongo_wallet' && $provider !== '' && $provider !== 'paymongo') {
            return $this->blockedDestination('PayMongo wallet destinations must use provider paymongo.');
        }

        return [
            'provider' => $this->name(),
            'ready' => true,
            'summary' => 'PayMongo destination preflight passed.',
            'blocking_reason' => null,
            'details' => [
                'destination_type' => $destinationType,
                'provider' => $provider !== '' ? $provider : null,
                'bic' => $bic !== '' ? $bic : null,
            ],
        ];
    }

    public function dispatch(PayoutRequest $payoutRequest, PayoutExecutionAttempt $attempt): array
    {
        $readiness = $this->readiness();

        if (! $readiness['ready']) {
            throw new RuntimeException($readiness['blocking_reason'] ?? 'PayMongo payout execution is not ready.');
        }

        $destination = $this->destinationPreflight($payoutRequest);

        if (! $destination['ready']) {
            throw new RuntimeException($destination['blocking_reason'] ?? 'PayMongo payout destination is invalid.');
        }

        $walletId = $this->resolveWalletId();
        $provider = strtolower((string) Arr::get($payoutRequest->destination_snapshot ?? [], 'provider', 'instapay'));
        $destinationType = (string) ($payoutRequest->destination_type ?: 'bank');
        $callbackUrl = $this->resolveCallbackUrl($attempt);

        $payload = [
            'data' => [
                'attributes' => [
                    'amount' => (int) round(((float) $payoutRequest->amount) * 100),
                    'currency' => $payoutRequest->currency,
                    'provider' => $provider,
                    'destination' => [
                        'type' => $destinationType === 'paymongo_wallet' ? 'wallet' : 'bank',
                        'account' => $payoutRequest->destination_account_reference,
                        'account_name' => $payoutRequest->destination_account_name,
                        'bic' => Arr::get($payoutRequest->destination_snapshot ?? [], 'bic'),
                    ],
                    'callback_url' => $callbackUrl,
                    'notes' => $payoutRequest->notes,
                    'reference_id' => $attempt->execution_reference,
                    'metadata' => [
                        'payout_request_id' => (string) $payoutRequest->id,
                        'execution_reference' => $attempt->execution_reference,
                        'idempotency_key' => $attempt->idempotency_key,
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->acceptJson()
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->withHeaders([
                    'Idempotency-Key' => $attempt->idempotency_key,
                ])
                ->post("{$this->baseUrl}/wallets/{$walletId}/transactions", $payload)
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            $response = $exception->response?->json() ?? [
                'message' => $exception->getMessage(),
            ];

            throw new RuntimeException(
                Arr::get($response, 'errors.0.detail')
                    ?? Arr::get($response, 'message')
                    ?? 'PayMongo payout execution dispatch failed.'
            );
        }

        return $this->mapTransferResponse($payload, $response, 'dispatch');
    }

    public function reconcile(PayoutRequest $payoutRequest, PayoutExecutionAttempt $attempt, ?array $providerPayload = null, string $source = 'poll'): array
    {
        if ($source === 'callback') {
            if (! is_array($providerPayload)) {
                throw new RuntimeException('PayMongo callback payload is required for callback reconciliation.');
            }

            return $this->mapTransferResponse(null, $providerPayload, 'callback');
        }

        $externalReference = trim((string) $attempt->external_reference);

        if ($externalReference === '') {
            throw new RuntimeException('PayMongo execution reconcile requires an external reference.');
        }

        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->acceptJson()
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->get("{$this->baseUrl}/wallets/{$this->resolveWalletId()}/transactions/{$externalReference}")
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            $response = $exception->response?->json() ?? [
                'message' => $exception->getMessage(),
            ];

            throw new RuntimeException(
                Arr::get($response, 'errors.0.detail')
                    ?? Arr::get($response, 'message')
                    ?? 'PayMongo payout execution reconcile failed.'
            );
        }

        return $this->mapTransferResponse(null, $response, 'poll');
    }

    public function verifyCallback(string $payload, ?string $signatureHeader): bool
    {
        return $this->signatureVerifier->verify(
            $payload,
            $signatureHeader,
            $this->callbackSecret,
            (int) config('services.paymongo.webhook_tolerance_seconds', 300),
        );
    }

    private function resolveWalletId(): string
    {
        $walletId = $this->resolveWalletIdValue();

        if ($walletId === '') {
            throw new RuntimeException('PayMongo payout wallet configuration is incomplete.');
        }

        return $walletId;
    }

    private function resolveWalletIdValue(): string
    {
        return trim((string) config('services.paymongo.payout_wallet_id', config('payouts.providers.paymongo.wallet_id')));
    }

    private function resolveCallbackBaseUrl(): string
    {
        return rtrim((string) (
            config('services.paymongo.payout_callback_url')
            ?: config('payouts.providers.paymongo.callback_url')
            ?: config('app.url')
        ), '/');
    }

    private function resolveCallbackUrl(PayoutExecutionAttempt $attempt): string
    {
        $relativePath = route('api.paymongo.payout-executions.callback', ['payoutExecutionAttempt' => $attempt->id], false);
        $callbackBaseUrl = $this->resolveCallbackBaseUrl();

        if ($callbackBaseUrl === '') {
            return route('api.paymongo.payout-executions.callback', ['payoutExecutionAttempt' => $attempt->id]);
        }

        return $callbackBaseUrl.$relativePath;
    }

    private function isPublicCallbackBaseUrl(string $callbackBaseUrl): bool
    {
        $host = parse_url($callbackBaseUrl, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return false;
        }

        $normalizedHost = strtolower(trim($host));

        if (in_array($normalizedHost, ['localhost', '127.0.0.1', '::1'], true) || Str::endsWith($normalizedHost, ['.local', '.test'])) {
            return false;
        }

        if (filter_var($normalizedHost, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $normalizedHost,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false;
        }

        return true;
    }

    private function blockedReadiness(string $reason, array $details = []): array
    {
        return [
            'provider' => $this->name(),
            'ready' => false,
            'summary' => $reason,
            'blocking_reason' => $reason,
            'details' => $details,
        ];
    }

    private function blockedDestination(string $reason): array
    {
        return [
            'provider' => $this->name(),
            'ready' => false,
            'summary' => $reason,
            'blocking_reason' => $reason,
            'details' => [],
        ];
    }

    private function mapTransferResponse(?array $requestPayload, array $response, string $source): array
    {
        $attributes = Arr::get($response, 'data.attributes', []);
        $providerState = $this->normalizeProviderState((string) Arr::get($attributes, 'status', ''));
        $externalReference = Arr::get($response, 'data.id')
            ?? Arr::get($attributes, 'reference_number')
            ?? Arr::get($attributes, 'transfer_id');

        return [
            'provider_name' => $this->name(),
            'provider_state' => $providerState !== '' ? $providerState : 'unknown',
            'execution_state' => $this->mapProviderStateToExecutionState($providerState),
            'external_reference' => is_string($externalReference) ? $externalReference : null,
            'provider_request_metadata' => $requestPayload,
            'provider_response_metadata' => [
                'source' => $source,
                'raw' => $response,
                'reference_number' => Arr::get($attributes, 'reference_number'),
                'transfer_id' => Arr::get($attributes, 'transfer_id'),
                'status_message' => Arr::get($attributes, 'status_message'),
                'failure_code' => Arr::get($attributes, 'provider_error_code'),
            ],
            'last_error' => $this->resolveProviderError($attributes, $providerState),
            'completed_at' => $providerState === 'succeeded' ? now() : null,
        ];
    }

    private function mapProviderStateToExecutionState(string $providerState): string
    {
        return match ($providerState) {
            'succeeded' => PayoutExecutionAttempt::STATE_COMPLETED,
            'failed' => PayoutExecutionAttempt::STATE_RETRYABLE_FAILED,
            'pending', 'processing', 'in_transit' => PayoutExecutionAttempt::STATE_DISPATCHED,
            PayoutExecutionAttempt::PROVIDER_STATE_REVERSED,
            PayoutExecutionAttempt::PROVIDER_STATE_ON_HOLD,
            PayoutExecutionAttempt::PROVIDER_STATE_COMPLIANCE_HOLD => PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
            PayoutExecutionAttempt::PROVIDER_STATE_RETURNED => PayoutExecutionAttempt::STATE_RETRYABLE_FAILED,
            PayoutExecutionAttempt::PROVIDER_STATE_REJECTED => PayoutExecutionAttempt::STATE_TERMINAL_FAILED,
            default => PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
        };
    }

    private function resolveProviderError(array $attributes, string $providerState): ?string
    {
        if ($providerState === 'failed') {
            return (string) (Arr::get($attributes, 'provider_error')
                ?? Arr::get($attributes, 'provider_error_code')
                ?? 'PayMongo transfer failed.');
        }

        if ($providerState === '' || ! in_array($providerState, ['pending', 'succeeded', 'failed'], true)) {
            return match ($providerState) {
                PayoutExecutionAttempt::PROVIDER_STATE_RETURNED => 'PayMongo reported the payout as returned. Destination details may be wrong or incomplete.',
                PayoutExecutionAttempt::PROVIDER_STATE_REVERSED => 'PayMongo reported the payout as reversed after an earlier positive state.',
                PayoutExecutionAttempt::PROVIDER_STATE_REJECTED => 'PayMongo reported the payout as rejected.',
                PayoutExecutionAttempt::PROVIDER_STATE_ON_HOLD,
                PayoutExecutionAttempt::PROVIDER_STATE_COMPLIANCE_HOLD => 'PayMongo reported the payout as on hold and manual review is required.',
                default => 'PayMongo transfer returned an ambiguous provider status.',
            };
        }

        return null;
    }

    private function normalizeProviderState(string $providerState): string
    {
        $providerState = strtolower(trim($providerState));

        return match ($providerState) {
            'on hold', 'hold', 'held' => PayoutExecutionAttempt::PROVIDER_STATE_ON_HOLD,
            'compliance hold', 'compliance_hold' => PayoutExecutionAttempt::PROVIDER_STATE_COMPLIANCE_HOLD,
            default => str_replace([' ', '-'], '_', $providerState),
        };
    }

    private function resolveMode(): string
    {
        return Str::startsWith($this->secretKey, 'sk_live_') ? 'live' : 'test';
    }
}
