<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayoutRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_PENDING = self::STATUS_PENDING_REVIEW;
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REVIEW_REQUIRED = 'review_required';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';

    public const SETTLEMENT_STATE_NOT_READY = 'not_ready';
    public const SETTLEMENT_STATE_READY = 'ready';
    public const SETTLEMENT_STATE_BLOCKED_UNDERFUNDED = 'blocked_underfunded';
    public const SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW = 'blocked_manual_review';
    public const SETTLEMENT_STATE_REVERSED = 'reversed';
    public const SETTLEMENT_STATE_SETTLED = 'settled';

    public const SETTLEMENT_BLOCK_UNDERFUNDED = 'underfunded_by_balance_change';
    public const SETTLEMENT_BLOCK_CONFIDENCE_DEGRADED = 'accounting_confidence_degraded';
    public const SETTLEMENT_BLOCK_LEGACY_EXECUTION_STATUS = 'legacy_execution_status_requires_manual_resolution';
    public const SETTLEMENT_BLOCK_REVERSED = 'settlement_reversed_requires_manual_resolution';
    public const SETTLEMENT_BLOCK_PROVIDER_NEGATIVE_OUTCOME = 'provider_negative_outcome_requires_manual_review';

    public const POST_EXECUTION_STATE_COMPLETED_AWAITING_SETTLEMENT = 'execution_completed_awaiting_settlement';
    public const POST_EXECUTION_STATE_COMPLETED_BLOCKED_FROM_SETTLEMENT = 'execution_completed_but_blocked_from_settlement';
    public const POST_EXECUTION_STATE_FAILED_RETRYABLE = 'execution_failed_retryable';
    public const POST_EXECUTION_STATE_MANUAL_FOLLOWUP_REQUIRED = 'execution_manual_followup_required';
    public const POST_EXECUTION_STATE_TERMINAL_FAILED = 'execution_terminal_failed';
    public const POST_EXECUTION_STATE_PROVIDER_RETURNED = 'execution_provider_returned_under_review';
    public const POST_EXECUTION_STATE_PROVIDER_REVERSED = 'execution_provider_reversed_under_review';
    public const POST_EXECUTION_STATE_PROVIDER_REJECTED = 'execution_provider_rejected_under_review';
    public const POST_EXECUTION_STATE_PROVIDER_ON_HOLD = 'execution_provider_on_hold_under_review';

    public const MODE_MANUAL = 'manual';
    public const MODE_PAYMONGO_TRANSFER = 'paymongo_transfer';

    public const RESERVING_STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REVIEW_REQUIRED,
        self::STATUS_PROCESSING,
    ];

    public const SETTLED_STATUSES = [
        self::STATUS_SETTLED,
        self::STATUS_PAID,
    ];

    public const REVIEWABLE_STATUSES = [
        self::STATUS_PENDING_REVIEW,
    ];

    public const CANCELLABLE_STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_APPROVED,
    ];

    protected $fillable = [
        'operator_id',
        'reviewed_by_user_id',
        'cancelled_by_user_id',
        'invalidated_by_user_id',
        'amount',
        'currency',
        'status',
        'settlement_state',
        'settlement_block_reason',
        'post_execution_state',
        'post_execution_reason',
        'post_execution_updated_at',
        'post_execution_handed_off_at',
        'post_execution_handed_off_by_user_id',
        'requested_at',
        'reviewed_at',
        'cancelled_at',
        'settlement_checked_at',
        'settlement_ready_at',
        'invalidated_at',
        'paid_at',
        'notes',
        'review_notes',
        'cancellation_reason',
        'destination_type',
        'destination_account_name',
        'destination_account_reference',
        'destination_snapshot',
        'metadata',
        'processing_mode',
        'provider',
        'provider_transfer_reference',
        'provider_status',
        'provider_response',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'settlement_checked_at' => 'datetime',
            'settlement_ready_at' => 'datetime',
            'post_execution_updated_at' => 'datetime',
            'post_execution_handed_off_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'paid_at' => 'datetime',
            'destination_snapshot' => 'array',
            'metadata' => 'array',
            'provider_response' => 'array',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function invalidatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invalidated_by_user_id');
    }

    public function postExecutionHandedOffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'post_execution_handed_off_by_user_id');
    }

    public function settlement(): HasOne
    {
        return $this->hasOne(PayoutSettlement::class);
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(PayoutRequestResolution::class);
    }

    public function latestResolution(): HasOne
    {
        return $this->hasOne(PayoutRequestResolution::class)->latestOfMany('resolved_at');
    }

    public function executionAttempts(): HasMany
    {
        return $this->hasMany(PayoutExecutionAttempt::class);
    }

    public function latestExecutionAttempt(): HasOne
    {
        return $this->hasOne(PayoutExecutionAttempt::class)->latestOfMany('triggered_at');
    }

    public function postExecutionEvents(): HasMany
    {
        return $this->hasMany(PayoutPostExecutionEvent::class);
    }

    public function latestPostExecutionEvent(): HasOne
    {
        return $this->hasOne(PayoutPostExecutionEvent::class)->latestOfMany('event_at');
    }

    public function scopeForOperator(Builder $query, Operator $operator): Builder
    {
        return $query->where('operator_id', $operator->id);
    }
}
