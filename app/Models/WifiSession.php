<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WifiSession extends Model
{
    use HasFactory;

    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const PAYMENT_STATUS_PAID = 'paid';
    public const PAYMENT_STATUS_EXPIRED = 'expired';
    public const PAYMENT_STATUS_FAILED = 'failed';
    public const PAYMENT_STATUS_CANCELED = 'canceled';

    public const SESSION_STATUS_PENDING_PAYMENT = 'pending_payment';
    public const SESSION_STATUS_PAID = 'paid';
    public const SESSION_STATUS_ACTIVE = 'active';
    public const SESSION_STATUS_EXPIRED = 'expired';
    public const SESSION_STATUS_RELEASE_FAILED = 'release_failed';
    public const SESSION_STATUS_MERGED = 'merged';

    public const RELEASE_STATUS_PENDING = 'pending';
    public const RELEASE_STATUS_IN_PROGRESS = 'in_progress';
    public const RELEASE_STATUS_SUCCEEDED = 'succeeded';
    public const RELEASE_STATUS_FAILED = 'failed';
    public const RELEASE_STATUS_UNCERTAIN = 'uncertain';
    public const RELEASE_STATUS_MANUAL_REQUIRED = 'manual_required';

    public const RELEASE_OUTCOME_SUCCESS = 'success';
    public const RELEASE_OUTCOME_RETRYABLE_CONTROLLER_FAILURE = 'retryable_controller_failure';
    public const RELEASE_OUTCOME_RETRYABLE_TIMEOUT = 'retryable_timeout';
    public const RELEASE_OUTCOME_NON_RETRYABLE_CONFIGURATION_FAILURE = 'non_retryable_configuration_failure';
    public const RELEASE_OUTCOME_NON_RETRYABLE_VALIDATION_FAILURE = 'non_retryable_validation_failure';
    public const RELEASE_OUTCOME_UNCERTAIN_CONTROLLER_STATE = 'uncertain_controller_state';
    public const RELEASE_OUTCOME_MANUAL_FOLLOWUP_REQUIRED = 'manual_followup_required';

    public const STATUS_PENDING = self::PAYMENT_STATUS_PENDING;
    public const STATUS_PAID = self::PAYMENT_STATUS_PAID;
    public const STATUS_FAILED = self::PAYMENT_STATUS_FAILED;

    protected $fillable = [
        'client_id',
        'client_device_id',
        'mac_address',
        'plan_id',
        'site_id',
        'access_point_id',
        'ap_mac',
        'ap_name',
        'ssid_name',
        'radio_id',
        'client_ip',
        'amount_paid',
        'payment_status',
        'session_status',
        'release_status',
        'release_outcome_type',
        'release_attempt_count',
        'last_release_attempt_at',
        'last_release_error',
        'release_failure_reason',
        'controller_state_uncertain',
        'released_at',
        'last_reconciled_at',
        'reconcile_attempt_count',
        'last_reconcile_result',
        'release_stuck_at',
        'released_by_path',
        'release_metadata',
        'start_time',
        'end_time',
        'is_active',
        'paymongo_payment_intent_id',
        'extends_session_id',
        'merged_into_session_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'is_active' => 'boolean',
            'radio_id' => 'integer',
            'release_attempt_count' => 'integer',
            'last_release_attempt_at' => 'datetime',
            'controller_state_uncertain' => 'boolean',
            'released_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'reconcile_attempt_count' => 'integer',
            'release_stuck_at' => 'datetime',
            'release_metadata' => 'array',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function clientDevice(): BelongsTo
    {
        return $this->belongsTo(ClientDevice::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function extendsSession(): BelongsTo
    {
        return $this->belongsTo(self::class, 'extends_session_id');
    }

    public function mergedIntoSession(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_session_id');
    }

    public function scopeForOperator(Builder $query, Operator $operator): Builder
    {
        return $query->whereIn('site_id', $operator->sites()->select('id'));
    }
}
