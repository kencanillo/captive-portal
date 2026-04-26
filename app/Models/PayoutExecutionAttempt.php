<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PayoutExecutionAttempt extends Model
{
    use HasFactory;

    public const STATE_PENDING_EXECUTION = 'pending_execution';
    public const STATE_DISPATCHED = 'dispatched';
    public const STATE_MANUAL_FOLLOWUP_REQUIRED = 'manual_followup_required';
    public const STATE_RETRYABLE_FAILED = 'retryable_failed';
    public const STATE_TERMINAL_FAILED = 'terminal_failed';
    public const STATE_FAILED = self::STATE_TERMINAL_FAILED;
    public const STATE_COMPLETED = 'completed';

    public const PROVIDER_STATE_RETURNED = 'returned';
    public const PROVIDER_STATE_REVERSED = 'reversed';
    public const PROVIDER_STATE_REJECTED = 'rejected';
    public const PROVIDER_STATE_ON_HOLD = 'on_hold';
    public const PROVIDER_STATE_COMPLIANCE_HOLD = 'compliance_hold';

    public const NEGATIVE_PROVIDER_STATES = [
        self::PROVIDER_STATE_RETURNED,
        self::PROVIDER_STATE_REVERSED,
        self::PROVIDER_STATE_REJECTED,
        self::PROVIDER_STATE_ON_HOLD,
        self::PROVIDER_STATE_COMPLIANCE_HOLD,
    ];

    public const ACTIVE_STATES = [
        self::STATE_PENDING_EXECUTION,
        self::STATE_DISPATCHED,
        self::STATE_MANUAL_FOLLOWUP_REQUIRED,
    ];

    public const RETRYABLE_STATES = [
        self::STATE_RETRYABLE_FAILED,
    ];

    public const TERMINAL_STATES = [
        self::STATE_RETRYABLE_FAILED,
        self::STATE_TERMINAL_FAILED,
        self::STATE_COMPLETED,
    ];

    public const STALE_CANDIDATE_STATES = [
        self::STATE_PENDING_EXECUTION,
        self::STATE_DISPATCHED,
        self::STATE_MANUAL_FOLLOWUP_REQUIRED,
    ];

    protected $fillable = [
        'payout_request_id',
        'payout_settlement_id',
        'operator_id',
        'parent_attempt_id',
        'amount',
        'currency',
        'execution_state',
        'execution_reference',
        'idempotency_key',
        'external_reference',
        'triggered_at',
        'triggered_by_user_id',
        'provider_name',
        'provider_state',
        'provider_state_source',
        'provider_state_checked_at',
        'last_provider_payload_hash',
        'provider_request_metadata',
        'provider_response_metadata',
        'last_error',
        'last_reconciled_at',
        'stale_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'triggered_at' => 'datetime',
            'provider_state_checked_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'stale_at' => 'datetime',
            'completed_at' => 'datetime',
            'provider_request_metadata' => 'array',
            'provider_response_metadata' => 'array',
        ];
    }

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(PayoutRequest::class);
    }

    public function payoutSettlement(): BelongsTo
    {
        return $this->belongsTo(PayoutSettlement::class);
    }

    public function parentAttempt(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_attempt_id');
    }

    public function retryAttempts(): HasMany
    {
        return $this->hasMany(self::class, 'parent_attempt_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function resolutions(): HasMany
    {
        return $this->hasMany(PayoutExecutionAttemptResolution::class);
    }

    public function latestResolution(): HasOne
    {
        return $this->hasOne(PayoutExecutionAttemptResolution::class)->latestOfMany('resolved_at');
    }

    public function isActive(): bool
    {
        return in_array($this->execution_state, self::ACTIVE_STATES, true);
    }

    public function isRetryEligible(): bool
    {
        return in_array($this->execution_state, self::RETRYABLE_STATES, true);
    }

    public function canBeMarkedCompleted(): bool
    {
        return in_array($this->execution_state, [
            self::STATE_PENDING_EXECUTION,
            self::STATE_DISPATCHED,
            self::STATE_MANUAL_FOLLOWUP_REQUIRED,
        ], true);
    }

    public function canBeMarkedTerminalFailed(): bool
    {
        return in_array($this->execution_state, [
            self::STATE_PENDING_EXECUTION,
            self::STATE_DISPATCHED,
            self::STATE_MANUAL_FOLLOWUP_REQUIRED,
            self::STATE_RETRYABLE_FAILED,
        ], true);
    }

    public function isStale(?CarbonInterface $at = null): bool
    {
        if (! in_array($this->execution_state, self::STALE_CANDIDATE_STATES, true)) {
            return false;
        }

        $baseline = $this->last_reconciled_at ?? $this->triggered_at;

        if (! $baseline) {
            return false;
        }

        $minutes = $this->staleThresholdMinutes();

        if ($minutes === null) {
            return false;
        }

        $at ??= now();

        return $baseline->copy()->addMinutes($minutes)->lte($at);
    }

    public function staleThresholdMinutes(): ?int
    {
        return match ($this->execution_state) {
            self::STATE_PENDING_EXECUTION => (int) config('payouts.execution.pending_stale_minutes', 15),
            self::STATE_DISPATCHED => (int) config('payouts.execution.dispatched_stale_minutes', 60),
            self::STATE_MANUAL_FOLLOWUP_REQUIRED => (int) config('payouts.execution.manual_followup_stale_minutes', 240),
            default => null,
        };
    }

    public function staleReason(): ?string
    {
        if (! $this->isStale()) {
            return null;
        }

        return match ($this->execution_state) {
            self::STATE_PENDING_EXECUTION => 'Execution attempt has been pending too long without dispatch resolution.',
            self::STATE_DISPATCHED => 'Execution attempt has been dispatched too long without reconciliation.',
            self::STATE_MANUAL_FOLLOWUP_REQUIRED => 'Execution attempt has been waiting on manual follow-up too long.',
            default => null,
        };
    }

    public function hasNegativeProviderOutcome(): bool
    {
        return in_array((string) $this->provider_state, self::NEGATIVE_PROVIDER_STATES, true);
    }
}
