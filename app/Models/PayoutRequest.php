<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';

    public const MODE_MANUAL = 'manual';
    public const MODE_PAYMONGO_TRANSFER = 'paymongo_transfer';

    protected $fillable = [
        'operator_id',
        'reviewed_by_user_id',
        'amount',
        'currency',
        'status',
        'requested_at',
        'reviewed_at',
        'paid_at',
        'notes',
        'review_notes',
        'destination_type',
        'destination_account_name',
        'destination_account_reference',
        'destination_snapshot',
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
            'paid_at' => 'datetime',
            'destination_snapshot' => 'array',
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

    public function scopeForOperator(Builder $query, Operator $operator): Builder
    {
        return $query->where('operator_id', $operator->id);
    }
}
