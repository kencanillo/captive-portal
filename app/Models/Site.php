<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory;

    protected $fillable = [
        'operator_id',
        'name',
        'slug',
        'omada_site_id',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function accessPoints(): HasMany
    {
        return $this->hasMany(AccessPoint::class);
    }

    public function wifiSessions(): HasMany
    {
        return $this->hasMany(WifiSession::class);
    }

    public function accessPointClaims(): HasMany
    {
        return $this->hasMany(AccessPointClaim::class);
    }

    public function accessPointOwnershipCorrections(): HasMany
    {
        return $this->hasMany(AccessPointOwnershipCorrection::class, 'to_site_id');
    }

    public function billingLedgerEntries(): HasMany
    {
        return $this->hasMany(BillingLedgerEntry::class);
    }
}
