<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'duration_minutes',
        'speed_limit',
        'is_active',
        'supports_pause',
        'enforce_no_tethering',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
            'supports_pause' => 'boolean',
            'enforce_no_tethering' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function wifiSessions(): HasMany
    {
        return $this->hasMany(WifiSession::class);
    }
}
