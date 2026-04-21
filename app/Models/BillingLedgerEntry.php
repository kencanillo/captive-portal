<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BillingLedgerEntry extends Model
{
    use HasFactory;

    public const ENTRY_TYPE_AP_CONNECTION_FEE = 'ap_connection_fee';

    public const DIRECTION_DEBIT = 'debit';
    public const DIRECTION_CREDIT = 'credit';

    public const STATE_POSTED = 'posted';
    public const STATE_REVERSED = 'reversed';

    public const SOURCE_AUTOMATION = 'automation';
    public const SOURCE_ADMIN_RUN = 'admin_run';
    public const SOURCE_ADMIN_REVERSAL = 'admin_reversal';

    protected $fillable = [
        'operator_id',
        'site_id',
        'access_point_id',
        'entry_type',
        'direction',
        'amount',
        'currency',
        'state',
        'billable_key',
        'triggered_at',
        'posted_at',
        'voided_at',
        'reversal_of_id',
        'source',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'triggered_at' => 'datetime',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
            'metadata' => 'array',
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

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversalEntry(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
