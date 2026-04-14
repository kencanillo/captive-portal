<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessPoint extends Model
{
    use HasFactory;

    public const CLAIM_STATUS_UNCLAIMED = 'unclaimed';
    public const CLAIM_STATUS_PENDING = 'pending';
    public const CLAIM_STATUS_CLAIMED = 'claimed';
    public const CLAIM_STATUS_ERROR = 'error';

    protected $fillable = [
        'site_id',
        'serial_number',
        'omada_device_id',
        'name',
        'mac_address',
        'vendor',
        'model',
        'ip_address',
        'claim_status',
        'claimed_at',
        'last_synced_at',
        'custom_ssid',
        'voucher_ssid_name',
        'allow_client_pause',
        'block_tethering',
        'is_portal_enabled',
        'is_online',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_online' => 'boolean',
            'allow_client_pause' => 'boolean',
            'block_tethering' => 'boolean',
            'is_portal_enabled' => 'boolean',
            'claimed_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function wifiSessions(): HasMany
    {
        return $this->hasMany(WifiSession::class);
    }
}
