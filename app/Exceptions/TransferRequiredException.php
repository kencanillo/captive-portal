<?php

namespace App\Exceptions;

use App\Models\Client;
use App\Models\WifiSession;
use RuntimeException;

class TransferRequiredException extends RuntimeException
{
    public function __construct(
        private readonly Client $client,
        private readonly ?WifiSession $activeSession,
        private readonly string $requestedMacAddress,
    ) {
        parent::__construct('Device transfer is required before this account can be used on a different device.');
    }

    public static function forClient(Client $client, ?WifiSession $activeSession, string $requestedMacAddress): self
    {
        return new self($client, $activeSession, $requestedMacAddress);
    }

    public function context(): array
    {
        return [
            'code' => 'transfer_required',
            'has_active_entitlement' => $this->activeSession !== null,
            'masked_phone_number' => $this->maskPhoneNumber($this->client->phone_number),
        ];
    }

    public function client(): Client
    {
        return $this->client;
    }

    public function activeSession(): ?WifiSession
    {
        return $this->activeSession;
    }

    public function requestedMacAddress(): string
    {
        return $this->requestedMacAddress;
    }

    private function maskPhoneNumber(?string $phoneNumber): ?string
    {
        if (! $phoneNumber) {
            return null;
        }

        $trimmed = trim($phoneNumber);

        if (strlen($trimmed) <= 4) {
            return str_repeat('*', strlen($trimmed));
        }

        return str_repeat('*', max(0, strlen($trimmed) - 4)) . substr($trimmed, -4);
    }
}
