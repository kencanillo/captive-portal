<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceFeeSetting extends Model
{
    use HasFactory;

    public const TYPE_SITE_WIDE = 'site_wide';
    public const TYPE_OPERATOR_SPECIFIC = 'operator_specific';
    public const TYPE_REVENUE_TIER = 'revenue_tier';

    protected $fillable = [
        'type',
        'operator_id',
        'fee_rate',
        'revenue_threshold_min',
        'revenue_threshold_max',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'fee_rate' => 'decimal:4',
            'revenue_threshold_min' => 'decimal:2',
            'revenue_threshold_max' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSiteWide(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SITE_WIDE);
    }

    public function scopeOperatorSpecific(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_OPERATOR_SPECIFIC);
    }

    public function scopeRevenueTier(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_REVENUE_TIER);
    }

    public function scopeForOperator(Builder $query, Operator $operator): Builder
    {
        return $query->where('operator_id', $operator->id);
    }

    public function scopeForRevenue(Builder $query, float $revenue): Builder
    {
        return $query->where(function (Builder $q) use ($revenue) {
            $q->where(function (Builder $subQ) use ($revenue) {
                $subQ->where('revenue_threshold_min', '<=', $revenue)
                     ->whereNull('revenue_threshold_max');
            })->orWhere(function (Builder $subQ) use ($revenue) {
                $subQ->where('revenue_threshold_min', '<=', $revenue)
                     ->where('revenue_threshold_max', '>=', $revenue);
            });
        });
    }

    public function appliesToRevenue(float $revenue): bool
    {
        if ($this->type !== self::TYPE_REVENUE_TIER) {
            return false;
        }

        if ($this->revenue_threshold_min && $revenue < $this->revenue_threshold_min) {
            return false;
        }

        if ($this->revenue_threshold_max && $revenue > $this->revenue_threshold_max) {
            return false;
        }

        return true;
    }

    public function getFeeRateAsPercentage(): string
    {
        return ($this->fee_rate * 100) . '%';
    }
}
