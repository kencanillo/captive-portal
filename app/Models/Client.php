<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone_number',
        'pin',
        'mac_address',
        'last_connected_at',
    ];

    protected function casts(): array
    {
        return [
            'last_connected_at' => 'datetime',
        ];
    }

    public function wifiSessions(): HasMany
    {
        return $this->hasMany(WifiSession::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(ClientDevice::class);
    }

    public function deviceTransferRequests(): HasMany
    {
        return $this->hasMany(DeviceTransferRequest::class);
    }

    public static function findByMacAddress(string $macAddress): ?self
    {
        $normalizedMacAddress = strtolower($macAddress);

        $device = ClientDevice::query()
            ->with('client')
            ->whereRaw('LOWER(mac_address) = ?', [$normalizedMacAddress])
            ->first();

        if ($device?->client) {
            $client = $device->client;

            if (strtolower((string) $client->mac_address) !== $normalizedMacAddress) {
                // Legacy compatibility only. Device ownership lives in client_devices.
                $client->forceFill(['mac_address' => $normalizedMacAddress])->save();
            }

            return $client;
        }

        $legacyClient = static::query()
            ->whereRaw('LOWER(mac_address) = ?', [$normalizedMacAddress])
            ->first();

        if (! $legacyClient) {
            return null;
        }

        // Legacy fallback is allowed only for pre-migration rows that do not have client_devices yet.
        return $legacyClient->devices()->exists() ? null : $legacyClient;
    }

    public static function findByPhoneNumber(string $phoneNumber): ?self
    {
        return static::where('phone_number', $phoneNumber)->first();
    }
}
