<?php

namespace App\Services;

use App\Models\AccessPoint;
use App\Models\Site;
use Illuminate\Support\Str;

class PortalSessionContextResolver
{
    public function resolve(array $attributes): array
    {
        $siteName = $this->cleanString($attributes['site_name'] ?? null);
        $apName = $this->cleanString($attributes['ap_name'] ?? null);
        $ssidName = $this->cleanString($attributes['ssid_name'] ?? null);
        $clientIp = filter_var($attributes['client_ip'] ?? null, FILTER_VALIDATE_IP) ?: null;
        $apMac = $this->normalizeMac($attributes['ap_mac'] ?? null);

        $site = null;
        $accessPoint = null;

        if ($apMac) {
            $accessPoint = AccessPoint::query()->with('site')->firstWhere('mac_address', $apMac);

            if ($accessPoint?->site) {
                $site = $accessPoint->site;
            } elseif ($siteName) {
                $site = $this->firstOrCreateSite($siteName);
            }

            if (! $accessPoint) {
                $accessPoint = AccessPoint::query()->create([
                    'site_id' => $site?->id,
                    'name' => $apName ?: $apMac,
                    'mac_address' => $apMac,
                    'is_online' => true,
                    'last_seen_at' => now(),
                ]);
            } else {
                $accessPoint->fill([
                    'site_id' => $site?->id ?? $accessPoint->site_id,
                    'name' => $apName ?: $accessPoint->name,
                    'is_online' => true,
                    'last_seen_at' => now(),
                ])->save();
            }
        } elseif ($siteName) {
            $site = $this->firstOrCreateSite($siteName);
        }

        return [
            'site_id' => $site?->id ?? $accessPoint?->site_id,
            'access_point_id' => $accessPoint?->id,
            'ap_mac' => $apMac,
            'ap_name' => $apName ?: $accessPoint?->name,
            'ssid_name' => $ssidName,
            'client_ip' => $clientIp,
        ];
    }

    private function firstOrCreateSite(string $siteName): Site
    {
        $site = Site::query()->firstWhere('name', $siteName);

        if ($site) {
            return $site;
        }

        $baseSlug = Str::slug($siteName) ?: 'site';
        $slug = $baseSlug;
        $suffix = 2;

        while (Site::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return Site::query()->create([
            'name' => $siteName,
            'slug' => $slug,
        ]);
    }

    private function cleanString(null|string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, 120, '') : null;
    }

    private function normalizeMac(null|string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value !== '' ? $value : null;
    }
}
