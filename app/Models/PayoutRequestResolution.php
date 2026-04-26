<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutRequestResolution extends Model
{
    use HasFactory;

    public const TYPE_CANCEL_AND_RELEASE = 'cancel_and_release';
    public const TYPE_RETURN_TO_REVIEW = 'return_to_review';

    protected $fillable = [
        'payout_request_id',
        'operator_id',
        'resolution_type',
        'resolved_at',
        'resolved_by_user_id',
        'reason',
        'notes',
        'resulting_status',
        'resulting_settlement_state',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
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

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
