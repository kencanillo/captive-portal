<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
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

        $placeholderSite = null;

        if ($omadaSiteId !== null) {
            $placeholderSite = self::query()
                ->whereNull('omada_site_id')
                ->where('name', $omadaSiteId)
                ->first();
        }

        if (! $site && $siteName !== null) {
            $site = self::query()->firstWhere('name', $siteName);
        }

        if (! $site && $placeholderSite) {
            $site = $placeholderSite;
        }

        if (! $site) {
            return self::query()->create([
                'name' => $siteName ?? $omadaSiteId,
                'slug' => self::uniqueSlug($siteName ?? $omadaSiteId ?? 'site'),
                'omada_site_id' => $omadaSiteId,
            ]);
        }

        if ($placeholderSite && $placeholderSite->id !== $site->id) {
            $site = self::mergePlaceholderSite($site, $placeholderSite);
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

    private static function mergePlaceholderSite(self $targetSite, self $placeholderSite): self
    {
        DB::transaction(function () use ($targetSite, $placeholderSite): void {
            AccessPoint::query()
                ->where('site_id', $placeholderSite->id)
                ->update(['site_id' => $targetSite->id]);

            WifiSession::query()
                ->where('site_id', $placeholderSite->id)
                ->update(['site_id' => $targetSite->id]);

            if ($targetSite->operator_id === null && $placeholderSite->operator_id !== null) {
                $targetSite->operator_id = $placeholderSite->operator_id;
                $targetSite->save();
            }

            $placeholderSite->delete();
        });

        return $targetSite->refresh();
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
