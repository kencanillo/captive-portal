<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccessPoint extends Model
{
    use HasFactory;

    public const CLAIM_STATUS_UNCLAIMED = 'unclaimed';
    public const CLAIM_STATUS_PENDING = 'pending';
    public const CLAIM_STATUS_CLAIMED = 'claimed';
    public const CLAIM_STATUS_ERROR = 'error';

    public const ADOPTION_STATE_UNCLAIMED = 'unclaimed';
    public const ADOPTION_STATE_ADOPTION_PENDING = 'adoption_pending';
    public const ADOPTION_STATE_ADOPTED = 'adopted';
    public const ADOPTION_STATE_ADOPTION_FAILED = 'adoption_failed';

    public const HEALTH_STATE_CONNECTED = 'connected';
    public const HEALTH_STATE_HEARTBEAT_MISSED = 'heartbeat_missed';
    public const HEALTH_STATE_DISCONNECTED = 'disconnected';
    public const HEALTH_STATE_PENDING = 'pending';
    public const HEALTH_STATE_STALE_UNKNOWN = 'stale_unknown';

    public const STATUS_SOURCE_SYNC = 'sync';
    public const STATUS_SOURCE_RECONCILE = 'reconcile';
    public const STATUS_SOURCE_WEBHOOK = 'webhook';

    public const BILLING_STATE_UNBILLED = 'unbilled';
    public const BILLING_STATE_PENDING_POST = 'pending_post';
    public const BILLING_STATE_BILLED = 'billed';
    public const BILLING_STATE_BLOCKED = 'blocked';
    public const BILLING_STATE_REVERSED = 'reversed';

    public const BILLING_INCIDENT_WAITING_FOR_FRESH_HEALTH = 'blocked_waiting_for_fresh_health';
    public const BILLING_INCIDENT_INVALID_OWNERSHIP = 'blocked_invalid_ownership';
    public const BILLING_INCIDENT_PREDATES_TRUSTED_OWNERSHIP = 'blocked_predates_trusted_ownership';
    public const BILLING_INCIDENT_CORRECTED_AFTER_BILLING = 'blocked_corrected_after_billing';
    public const BILLING_INCIDENT_AUTOMATION_DEGRADED = 'blocked_automation_degraded';
    public const BILLING_INCIDENT_MANUAL_REVIEW_REQUIRED = 'blocked_manual_review_required';

    protected $fillable = [
        'site_id',
        'claimed_by_operator_id',
        'approved_claim_id',
        'serial_number',
        'omada_device_id',
        'name',
        'mac_address',
        'vendor',
        'model',
        'ip_address',
        'claim_status',
        'adoption_state',
        'claimed_at',
        'ownership_verified_at',
        'ownership_verified_by_user_id',
        'ownership_corrected_at',
        'ownership_corrected_by_user_id',
        'latest_correction_reason',
        'billing_state',
        'billing_posted_at',
        'billing_block_reason',
        'billing_incident_state',
        'billing_incident_opened_at',
        'billing_incident_resolved_at',
        'billing_eligibility_confirmed_at',
        'billing_eligibility_confirmed_by_user_id',
        'latest_billing_resolution_reason',
        'billing_resolution_metadata',
        'billing_charge_generation',
        'latest_billing_entry_id',
        'last_synced_at',
        'custom_ssid',
        'voucher_ssid_name',
        'allow_client_pause',
        'block_tethering',
        'is_portal_enabled',
        'is_online',
        'health_state',
        'health_checked_at',
        'status_source',
        'status_source_event_at',
        'last_seen_at',
        'first_connected_at',
        'last_connected_at',
        'first_confirmed_connected_at',
        'last_disconnected_at',
        'last_health_mismatch_at',
        'health_metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_online' => 'boolean',
            'allow_client_pause' => 'boolean',
            'block_tethering' => 'boolean',
            'is_portal_enabled' => 'boolean',
            'claimed_at' => 'datetime',
            'ownership_verified_at' => 'datetime',
            'ownership_corrected_at' => 'datetime',
            'billing_posted_at' => 'datetime',
            'billing_incident_opened_at' => 'datetime',
            'billing_incident_resolved_at' => 'datetime',
            'billing_eligibility_confirmed_at' => 'datetime',
            'billing_charge_generation' => 'integer',
            'billing_resolution_metadata' => 'array',
            'last_synced_at' => 'datetime',
            'health_checked_at' => 'datetime',
            'status_source_event_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'first_connected_at' => 'datetime',
            'last_connected_at' => 'datetime',
            'first_confirmed_connected_at' => 'datetime',
            'last_disconnected_at' => 'datetime',
            'last_health_mismatch_at' => 'datetime',
            'health_metadata' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function wifiSessions(): HasMany
    {
        return $this->hasMany(WifiSession::class);
    }

    public function approvedClaim(): BelongsTo
    {
        return $this->belongsTo(AccessPointClaim::class, 'approved_claim_id');
    }

    public function claimedByOperator(): BelongsTo
    {
        return $this->belongsTo(Operator::class, 'claimed_by_operator_id');
    }

    public function ownershipVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ownership_verified_by_user_id');
    }

    public function claimMatches(): HasMany
    {
        return $this->hasMany(AccessPointClaim::class, 'matched_access_point_id');
    }

    public function ownershipCorrections(): HasMany
    {
        return $this->hasMany(AccessPointOwnershipCorrection::class);
    }

    public function billingLedgerEntries(): HasMany
    {
        return $this->hasMany(BillingLedgerEntry::class);
    }

    public function latestBillingEntry(): BelongsTo
    {
        return $this->belongsTo(BillingLedgerEntry::class, 'latest_billing_entry_id');
    }

    public function billingEligibilityConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billing_eligibility_confirmed_by_user_id');
    }

    public function scopeForOperator(Builder $query, Operator $operator): Builder
    {
        return $query->where(function (Builder $query) use ($operator): void {
            $query->where('claimed_by_operator_id', $operator->id)
                ->orWhere(function (Builder $query) use ($operator): void {
                    $query->whereNull('claimed_by_operator_id')
                        ->whereIn('site_id', $operator->sites()->select('id'));
                });
        });
    }

    public function ownershipCorrectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ownership_corrected_by_user_id');
    }
}
