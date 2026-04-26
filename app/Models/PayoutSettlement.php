<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayoutSettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'payout_request_id',
        'operator_id',
        'amount',
        'currency',
        'settled_at',
        'settled_by_user_id',
        'settlement_reference',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'settled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(PayoutRequest::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_user_id');
    }

    public function correction(): HasOne
    {
        return $this->hasOne(PayoutSettlementCorrection::class);
    }

    public function executionAttempts(): HasMany
    {
        return $this->hasMany(PayoutExecutionAttempt::class);
    }
}
