<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessPointOwnershipCorrection extends Model
{
    use HasFactory;

    protected $fillable = [
        'access_point_id',
        'from_operator_id',
        'to_operator_id',
        'from_site_id',
        'to_site_id',
        'from_approved_claim_id',
        'corrected_by_user_id',
        'correction_reason',
        'notes',
        'metadata',
        'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'corrected_at' => 'datetime',
        ];
    }

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }

    public function fromOperator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'from_operator_id');
    }

    public function toOperator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'to_operator_id');
    }

    public function fromSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'from_site_id');
    }

    public function toSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'to_site_id');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by_user_id');
    }

    public function fromApprovedClaim(): BelongsTo
    {
        return $this->belongsTo(AccessPointClaim::class, 'from_approved_claim_id');
    }
}
