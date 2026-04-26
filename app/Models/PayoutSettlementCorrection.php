<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutSettlementCorrection extends Model
{
    use HasFactory;

    public const TYPE_REVERSAL = 'reversal';
    public const TYPE_PROVIDER_RETURN = 'provider_return';
    public const TYPE_PROVIDER_REVERSAL = 'provider_reversal';
    public const TYPE_PROVIDER_REJECTION = 'provider_rejection';
    public const TYPE_PROVIDER_HOLD = 'provider_hold';

    protected $fillable = [
        'payout_settlement_id',
        'payout_request_id',
        'operator_id',
        'correction_type',
        'corrected_at',
        'corrected_by_user_id',
        'reason',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'corrected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PayoutSettlement::class, 'payout_settlement_id');
    }

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(PayoutRequest::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by_user_id');
    }
}
