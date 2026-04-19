<?php

namespace App\Services\Payouts;

use App\Models\PayoutRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayMongoTransferPayoutProcessor implements PayoutProcessor
{
    public function mode(): string
    {
        return PayoutRequest::MODE_PAYMONGO_TRANSFER;
    }

    public function process(PayoutRequest $payoutRequest): array
    {
        if (! config('payouts.providers.paymongo.enabled')) {
            throw new RuntimeException('PayMongo payouts are disabled.');
        }

        $walletId = (string) config('payouts.providers.paymongo.wallet_id');
        $secretKey = (string) config('services.paymongo.secret_key');
        $baseUrl = rtrim((string) config('services.paymongo.base_url', 'https://api.paymongo.com/v1'), '/');

        if ($walletId === '' || $secretKey === '') {
            throw new RuntimeException('PayMongo payout wallet configuration is incomplete.');
        }

        $destinationType = (string) ($payoutRequest->destination_type ?: 'bank');
        $destination = $payoutRequest->destination_snapshot ?? [];
        $provider = (string) Arr::get($destination, 'provider', 'instapay');

        if (! filled($payoutRequest->destination_account_reference) || ! filled($payoutRequest->destination_account_name)) {
            throw new RuntimeException('Payout destination account details are incomplete.');
        }

        try {
            $response = Http::withBasicAuth($secretKey, '')
                ->acceptJson()
                ->post("{$baseUrl}/wallets/{$walletId}/transactions", [
                    'data' => [
                        'attributes' => [
                            'amount' => (int) round(((float) $payoutRequest->amount) * 100),
                            'currency' => $payoutRequest->currency,
                            'provider' => $provider,
                            'destination' => [
                                'type' => $destinationType === 'paymongo_wallet' ? 'wallet' : 'bank',
                                'account' => $payoutRequest->destination_account_reference,
                                'account_name' => $payoutRequest->destination_account_name,
                                'bic' => Arr::get($destination, 'bic'),
                            ],
                            'callback_url' => config('payouts.providers.paymongo.callback_url'),
                            'notes' => $payoutRequest->notes,
                            'reference_id' => sprintf('POR-%s', $payoutRequest->id),
                        ],
                    ],
                ])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            $response = $exception->response?->json() ?? [
                'message' => $exception->getMessage(),
            ];

            throw new RuntimeException(
                Arr::get($response, 'errors.0.detail')
                    ?? Arr::get($response, 'message')
                    ?? 'PayMongo payout request failed.'
            );
        }

        $remoteStatus = (string) Arr::get($response, 'data.attributes.status', 'processing');

        return [
            'processing_mode' => $this->mode(),
            'provider' => 'paymongo',
            'provider_status' => $remoteStatus,
            'provider_transfer_reference' => Arr::get($response, 'data.id')
                ?? Arr::get($response, 'data.attributes.reference_number')
                ?? Arr::get($response, 'data.attributes.instruction_id'),
            'provider_response' => $response,
            'status' => in_array($remoteStatus, ['success', 'paid', 'completed'], true)
                ? PayoutRequest::STATUS_PAID
                : PayoutRequest::STATUS_PROCESSING,
            'paid_at' => in_array($remoteStatus, ['success', 'paid', 'completed'], true) ? now() : null,
        ];
    }
}
