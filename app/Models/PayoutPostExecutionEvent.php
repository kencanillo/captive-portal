<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutPostExecutionEvent extends Model
{
    use HasFactory;

    public const TYPE_STATE_SYNC = 'state_sync';
    public const TYPE_SETTLEMENT_HANDOFF_CONFIRMED = 'settlement_handoff_confirmed';
    public const TYPE_PROVIDER_NEGATIVE_OUTCOME_LINKED = 'provider_negative_outcome_linked';

    protected $fillable = [
        'payout_request_id',
        'payout_execution_attempt_id',
        'operator_id',
        'event_type',
        'event_at',
        'event_by_user_id',
        'reason',
        'notes',
        'resulting_post_execution_state',
        'resulting_settlement_state',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(PayoutRequest::class);
    }

    public function payoutExecutionAttempt(): BelongsTo
    {
        return $this->belongsTo(PayoutExecutionAttempt::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function eventBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'event_by_user_id');
    }
}
