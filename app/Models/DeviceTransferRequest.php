<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceTransferRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_DENIED = 'denied';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'client_id',
        'active_wifi_session_id',
        'from_client_device_id',
        'reviewed_by_user_id',
        'requested_mac_address',
        'requested_phone_number',
        'status',
        'requested_at',
        'reviewed_at',
        'executed_at',
        'review_notes',
        'denial_reason',
        'failure_reason',
        'metadata',
        'execution_metadata',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'executed_at' => 'datetime',
            'metadata' => 'array',
            'execution_metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function activeWifiSession(): BelongsTo
    {
        return $this->belongsTo(WifiSession::class, 'active_wifi_session_id');
    }

    public function fromDevice(): BelongsTo
    {
        return $this->belongsTo(ClientDevice::class, 'from_client_device_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
