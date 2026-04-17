<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WifiSession extends Model
{
    use HasFactory;

    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const PAYMENT_STATUS_PAID = 'paid';
    public const PAYMENT_STATUS_EXPIRED = 'expired';
    public const PAYMENT_STATUS_FAILED = 'failed';
    public const PAYMENT_STATUS_CANCELED = 'canceled';

    public const SESSION_STATUS_PENDING_PAYMENT = 'pending_payment';
    public const SESSION_STATUS_PAID = 'paid';
    public const SESSION_STATUS_ACTIVE = 'active';
    public const SESSION_STATUS_EXPIRED = 'expired';
    public const SESSION_STATUS_RELEASE_FAILED = 'release_failed';

    public const STATUS_PENDING = self::PAYMENT_STATUS_PENDING;
    public const STATUS_PAID = self::PAYMENT_STATUS_PAID;
    public const STATUS_FAILED = self::PAYMENT_STATUS_FAILED;

    protected $fillable = [
        'client_id',
        'mac_address',
        'plan_id',
        'site_id',
        'access_point_id',
        'ap_mac',
        'ap_name',
        'ssid_name',
        'radio_id',
        'client_ip',
        'amount_paid',
        'payment_status',
        'session_status',
        'release_failure_reason',
        'start_time',
        'end_time',
        'is_active',
        'paymongo_payment_intent_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'is_active' => 'boolean',
            'radio_id' => 'integer',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->payments()->latestOfMany();
    }
}
