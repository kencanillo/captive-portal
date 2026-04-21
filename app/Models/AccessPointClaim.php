<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessPointClaim extends Model
{
    use HasFactory;

    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED = 'denied';
    public const STATUS_ADOPTION_PENDING = 'adoption_pending';
    public const STATUS_ADOPTED = 'adopted';
    public const STATUS_ADOPTION_FAILED = 'adoption_failed';

    public const MATCH_STATUS_UNMATCHED = 'unmatched';
    public const MATCH_STATUS_RESERVED = 'reserved';
    public const MATCH_STATUS_STALE_MATCH = 'stale_match';
    public const MATCH_STATUS_CONFLICT = 'conflict';
    public const MATCH_STATUS_STALE_SYNC = 'stale_sync';

    public const CONFLICT_STATE_SPLIT_FINGERPRINT = 'split_fingerprint';

    protected $fillable = [
        'operator_id',
        'site_id',
        'requested_serial_number',
        'requested_serial_number_normalized',
        'requested_mac_address',
        'requested_mac_address_normalized',
        'requested_ap_name',
        'claim_status',
        'claim_match_status',
        'claimed_at',
        'reviewed_at',
        'reviewed_by_user_id',
        'review_notes',
        'denial_reason',
        'matched_access_point_id',
        'matched_omada_device_id',
        'match_snapshot',
        'matched_at',
        'requires_re_review',
        'conflict_state',
        'sync_freshness_checked_at',
        'adoption_attempted_at',
        'adoption_result_metadata',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'match_snapshot' => 'array',
            'matched_at' => 'datetime',
            'requires_re_review' => 'boolean',
            'sync_freshness_checked_at' => 'datetime',
            'adoption_attempted_at' => 'datetime',
            'adoption_result_metadata' => 'array',
        ];
    }

    public static function openStatuses(): array
    {
        return [
            self::STATUS_SUBMITTED,
            self::STATUS_PENDING_REVIEW,
            self::STATUS_APPROVED,
            self::STATUS_ADOPTION_PENDING,
            self::STATUS_ADOPTION_FAILED,
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function matchedAccessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class, 'matched_access_point_id');
    }
}
