<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone_number',
        'normalized_phone_number',
        'pin',
        'mac_address',
        'last_connected_at',
        'last_transferred_at',
    ];

    protected function casts(): array
    {
        return [
            'last_connected_at' => 'datetime',
            'last_transferred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $client): void {
            $client->phone_number = trim((string) $client->phone_number);
            $client->normalized_phone_number = self::normalizePhoneNumber($client->phone_number);
            $client->mac_address = strtoupper(trim((string) $client->mac_address));
        });
    }

    public function wifiSessions(): HasMany
    {
        return $this->hasMany(WifiSession::class);
    }

    public static function findByMacAddress(string $macAddress): ?self
    {
        return static::query()
            ->whereRaw('LOWER(mac_address) = ?', [strtolower($macAddress)])
            ->first();
    }

    public static function findByPhoneNumber(string $phoneNumber): ?self
    {
        return static::query()
            ->where('normalized_phone_number', self::normalizePhoneNumber($phoneNumber))
            ->orderByDesc('last_connected_at')
            ->orderBy('id')
            ->first();
    }

    public static function findByPhoneAndPin(string $phoneNumber, string $pin): ?self
    {
        return static::query()
            ->where('normalized_phone_number', self::normalizePhoneNumber($phoneNumber))
            ->orderByDesc('last_connected_at')
            ->orderBy('id')
            ->get()
            ->first(fn (self $client): bool => Hash::check($pin, $client->pin));
    }

    public static function normalizePhoneNumber(string $phoneNumber): string
    {
        return preg_replace('/\D+/', '', trim($phoneNumber)) ?: '';
    }
}
