<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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

    public static function resolveFromOmada(?string $omadaSiteId, ?string $siteName): ?self
    {
        $omadaSiteId = self::cleanValue($omadaSiteId);
        $siteName = self::cleanValue($siteName);

        if ($omadaSiteId === null && $siteName === null) {
            return null;
        }

        $site = null;

        if ($omadaSiteId !== null) {
            $site = self::query()->firstWhere('omada_site_id', $omadaSiteId);
        }

        if (! $site && $siteName !== null) {
            $site = self::query()->firstWhere('name', $siteName);
        }

        if (! $site) {
            return self::query()->create([
                'name' => $siteName ?? $omadaSiteId,
                'slug' => self::uniqueSlug($siteName ?? $omadaSiteId ?? 'site'),
                'omada_site_id' => $omadaSiteId,
            ]);
        }

        $dirty = false;

        if ($omadaSiteId !== null && $site->omada_site_id !== $omadaSiteId) {
            $site->omada_site_id = $omadaSiteId;
            $dirty = true;
        }

        if ($siteName !== null && $site->name !== $siteName) {
            $site->name = $siteName;
            $dirty = true;
        }

        if ($dirty) {
            $site->save();
        }

        return $site;
    }

    private static function uniqueSlug(string $baseValue): string
    {
        $baseSlug = Str::slug($baseValue) ?: 'site';
        $slug = $baseSlug;
        $suffix = 2;

        while (self::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private static function cleanValue(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
