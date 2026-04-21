<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'operator_id',
        'hotspot_operator_username',
        'hotspot_operator_password',
        'notes',
    ];

    protected $hidden = [
        'hotspot_operator_password',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }
}
