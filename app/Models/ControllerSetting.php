<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ControllerSetting extends Model
{
    protected $fillable = [
        'controller_name',
        'base_url',
        'site_identifier',
        'site_name',
        'portal_base_url',
        'username',
        'password',
        'hotspot_operator_username',
        'hotspot_operator_password',
        'api_client_id',
        'api_client_secret',
        'default_session_minutes',
        'last_tested_at',
    ];

    protected $hidden = [
        'password',
        'hotspot_operator_password',
        'api_client_secret',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'hotspot_operator_password' => 'encrypted',
            'api_client_secret' => 'encrypted',
            'default_session_minutes' => 'integer',
            'last_tested_at' => 'datetime',
        ];
    }

    public static function singleton(): self
    {
        return static::query()->first() ?? new static([
            'controller_name' => 'Primary Omada Controller',
            'base_url' => 'https://controller.example.com',
            'default_session_minutes' => 60,
        ]);
    }

    public function hasLegacyCredentials(): bool
    {
        return filled($this->username) && filled($this->password);
    }

    public function hasOpenApiCredentials(): bool
    {
        return filled($this->api_client_id) && filled($this->api_client_secret);
    }

    public function canTestConnection(): bool
    {
        return filled($this->base_url) && ($this->hasLegacyCredentials() || $this->hasOpenApiCredentials());
    }

    public function canSyncAccessPoints(): bool
    {
        return filled($this->base_url) && $this->hasLegacyCredentials();
    }

    public function hasHotspotOperatorCredentials(): bool
    {
        return filled($this->hotspot_operator_username) && filled($this->hotspot_operator_password);
    }
}
