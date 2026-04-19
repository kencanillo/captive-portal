<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    public const PROVIDER_PAYMONGO = 'paymongo';
    public const FLOW_QRPH = 'qrph';

    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'wifi_session_id',
        'provider',
        'payment_flow',
        'reference_id',
        'status',
        'raw_response',
        'paymongo_payment_intent_id',
        'paymongo_payment_method_id',
        'paymongo_payment_id',
        'qr_reference',
        'qr_image_url',
        'qr_expires_at',
        'paid_at',
        'webhook_last_event_id',
        'webhook_last_payload',
        'webhook_received_at',
        'failure_reason',
        'amount',
        'currency',
    ];

    protected $appends = [
        'payment_status',
        'external_reference',
    ];

    protected function casts(): array
    {
        return [
            'raw_response' => 'array',
            'webhook_last_payload' => 'array',
            'amount' => 'decimal:2',
            'qr_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'webhook_received_at' => 'datetime',
        ];
    }

    public function wifiSession(): BelongsTo
    {
        return $this->belongsTo(WifiSession::class);
    }

    public function paymentStatus(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['status'] ?? null,
            set: fn (?string $value) => ['status' => $value]
        );
    }

    public function externalReference(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['reference_id'] ?? null,
            set: fn (?string $value) => ['reference_id' => $value]
        );
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_PAID,
            self::STATUS_EXPIRED,
            self::STATUS_FAILED,
            self::STATUS_CANCELED,
        ], true);
    }

    public function shouldContinuePolling(): bool
    {
        return ! $this->isTerminal();
    }

    public function scopeForOperator(Builder $query, Operator $operator): Builder
    {
        return $query->whereHas('wifiSession', fn (Builder $wifiSessions) => $wifiSessions->forOperator($operator));
    }
}
