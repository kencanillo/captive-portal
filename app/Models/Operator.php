<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Operator extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'business_name',
        'contact_name',
        'phone_number',
        'status',
        'requested_site_name',
        'payout_preferences',
        'approval_notes',
        'reviewed_at',
        'reviewed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'payout_preferences' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function payoutRequests(): HasMany
    {
        return $this->hasMany(PayoutRequest::class);
    }

    public function payoutSettlements(): HasMany
    {
        return $this->hasMany(PayoutSettlement::class);
    }

    public function accessPointClaims(): HasMany
    {
        return $this->hasMany(AccessPointClaim::class);
    }

    public function accessPoints(): HasMany
    {
        return $this->hasMany(AccessPoint::class, 'claimed_by_operator_id');
    }

    public function accessPointOwnershipCorrections(): HasMany
    {
        return $this->hasMany(AccessPointOwnershipCorrection::class, 'to_operator_id');
    }

    public function billingLedgerEntries(): HasMany
    {
        return $this->hasMany(BillingLedgerEntry::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
