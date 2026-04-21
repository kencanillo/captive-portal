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
        $siteIdentifier = $this->cleanString($attributes['site_identifier'] ?? null);
        $apName = $this->cleanString($attributes['ap_name'] ?? null);
        $ssidName = $this->cleanString($attributes['ssid_name'] ?? null);
        $radioId = $this->normalizeRadioId($attributes['radio_id'] ?? null);
        $clientIp = filter_var($attributes['client_ip'] ?? null, FILTER_VALIDATE_IP) ?: null;
        $apMac = $this->normalizeMac($attributes['ap_mac'] ?? null);

        $site = null;
        $accessPoint = null;
        $resolvedSite = ($siteIdentifier || $siteName)
            ? Site::resolveFromOmada($siteIdentifier, $siteName)
            : null;

        if ($apMac) {
            $accessPoint = AccessPoint::query()->with('site')->firstWhere('mac_address', $apMac);

            if ($resolvedSite) {
                $site = $resolvedSite;
            } elseif ($accessPoint?->site) {
                $site = $accessPoint->site;
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
        } elseif ($resolvedSite) {
            $site = $resolvedSite;
        }

        return [
            'site_id' => $site?->id ?? $accessPoint?->site_id,
            'access_point_id' => $accessPoint?->id,
            'ap_mac' => $apMac,
            'ap_name' => $apName ?: $accessPoint?->name,
            'site_identifier' => $site?->omada_site_id ?? $siteIdentifier,
            'ssid_name' => $ssidName,
            'radio_id' => $radioId,
            'client_ip' => $clientIp,
        ];
    }

    private function cleanString(null|string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, 120, '') : null;
    }

    private function normalizeMac(null|string $value): ?string
    {
        $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string) $value) ?? '');

        if (strlen($mac) !== 12) {
            return null;
        }

        return implode(':', str_split($mac, 2));
    }

    private function normalizeRadioId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $radioId = (int) $value;

        return $radioId >= 0 ? $radioId : null;
    }
}
