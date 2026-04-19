<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $appends = [
        'processing_fee_rate',
        'processing_fee_amount',
        'customer_price',
    ];

    protected $fillable = [
        'name',
        'description',
        'price',
        'duration_minutes',
        'speed_limit',
        'is_active',
        'supports_pause',
        'enforce_no_tethering',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
            'supports_pause' => 'boolean',
            'enforce_no_tethering' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function wifiSessions(): HasMany
    {
        return $this->hasMany(WifiSession::class);
    }

    public function processingFeeRate(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round((float) config('portal.ewallet_fee_rate', 0.02), 4),
        );
    }

    public function processingFeeAmount(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round((float) $this->price * $this->processing_fee_rate, 2),
        );
    }

    public function customerPrice(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round((float) $this->price + $this->processing_fee_amount, 2),
        );
    }
}
