<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutExecutionAttemptResolution extends Model
{
    use HasFactory;

    public const TYPE_RECONCILED_STALE = 'reconciled_stale';
    public const TYPE_MARKED_COMPLETED = 'marked_completed';
    public const TYPE_MARKED_TERMINAL_FAILED = 'marked_terminal_failed';
    public const TYPE_RETRIED = 'retried';

    protected $fillable = [
        'payout_execution_attempt_id',
        'payout_request_id',
        'operator_id',
        'resolution_type',
        'resolved_at',
        'resolved_by_user_id',
        'reason',
        'notes',
        'resulting_state',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function payoutExecutionAttempt(): BelongsTo
    {
        return $this->belongsTo(PayoutExecutionAttempt::class);
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
