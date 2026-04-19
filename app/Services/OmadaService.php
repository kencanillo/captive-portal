<?php

namespace App\Services;

use App\Models\AccessPoint;
use App\Models\ControllerSetting;
use App\Models\Site;
use App\Models\WifiSession;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OmadaService
{
    public function testConnection(ControllerSetting $settings): array
    {
        $normalized = $this->normalizeSettings($settings);
        $info = $this->extractControllerInfo(
            $this->request($this->client($normalized), 'get', '/api/info')
        );

        if ($this->hasOpenApiCredentials($normalized)) {
            $this->requestOpenApiAccessToken($normalized, $info['omadac_id']);

            return [
                'controller_name' => $info['controller_name'] ?? $normalized['controller_name'],
                'version' => $info['version'],
                'api_version' => $info['api_version'],
            ];
        }

        if (! $this->hasLegacyCredentials($normalized)) {
            throw new RuntimeException('Add either a local controller username/password or an OpenAPI client ID/secret before testing the connection.');
        }

        $client = $this->authenticatedClient($normalized, 'connection testing');
        $controllerSettings = $this->request($client, 'get', '/api/v2/controller/setting');

        return [
            'controller_name' => Arr::get($controllerSettings, 'result.name')
                ?? Arr::get($controllerSettings, 'result.controllerName')
                ?? $info['controller_name']
                ?? $normalized['controller_name'],
            'version' => $info['version'],
            'api_version' => $info['api_version'],
        ];
    }

    public function getClientMacAddress(ControllerSetting $settings, string $clientIp): ?string
    {
        return $this->lookupPortalClientContext($settings, $clientIp)['mac_address'];
    }

    public function lookupPortalClientContext(ControllerSetting $settings, ?string $clientIp, ?string $requestId = null): array
    {
        $lookupStartedAt = microtime(true);
        $normalized = $this->normalizeSettings($settings);
        $positiveCacheSeconds = max(0, (int) config('portal.omada_mac_lookup_cache_seconds', 45));
        $missCacheSeconds = max(0, (int) config('portal.device_context_miss_cache_seconds', 3));
        $snapshotCacheSeconds = max(0, (int) config('portal.omada_clients_snapshot_cache_seconds', 5));
        $retryAfterMs = max(250, (int) config('portal.device_context_retry_after_ms', 1500));
        $loginDurationMs = null;
        $clientsFetchDurationMs = null;

        if (blank($clientIp)) {
            return [
                'status' => 'failed',
                'resolution_source' => 'omada_unavailable',
                'mac_address' => null,
                'error_code' => 'missing_client_ip',
                'retry_after_ms' => $retryAfterMs,
            ];
        }

        $cacheKey = $this->portalMacLookupCacheKey($normalized['request_base_url'], $clientIp);
        $cachedLookup = $this->safeCacheGet($cacheKey);

        if (is_array($cachedLookup) && array_key_exists('mac_address', $cachedLookup)) {
            Log::info('Portal Omada MAC lookup cache hit.', [
                'request_id' => $requestId,
                'client_ip' => $clientIp,
                'matched' => filled($cachedLookup['mac_address']),
                'cache_type' => 'ip_lookup',
                'duration_ms' => $this->elapsedMilliseconds($lookupStartedAt),
            ]);

            return [
                'status' => filled($cachedLookup['mac_address']) ? 'resolved' : 'retryable',
                'resolution_source' => 'omada_ip_cache',
                'mac_address' => $cachedLookup['mac_address'] ?: null,
                'error_code' => $cachedLookup['error_code'] ?? (filled($cachedLookup['mac_address']) ? null : 'not_found'),
                'retry_after_ms' => filled($cachedLookup['mac_address']) ? 0 : $retryAfterMs,
            ];
        }

        try {
            $clientSnapshot = $this->safeCacheGet($this->portalClientsSnapshotCacheKey($normalized['request_base_url']));
            $snapshotCacheHit = is_array($clientSnapshot);

            if (! $snapshotCacheHit) {
                $loginStartedAt = microtime(true);
                $client = $this->authenticatedClient($normalized, 'client MAC lookup', $this->portalMacLookupTimeoutProfile());
                $loginDurationMs = $this->elapsedMilliseconds($loginStartedAt);

                $clientsFetchStartedAt = microtime(true);
                $payload = $this->request($client, 'get', '/api/v2/controller/clients');
                $clientsFetchDurationMs = $this->elapsedMilliseconds($clientsFetchStartedAt);
                $clientSnapshot = $this->extractClientsFromPayload($payload);

                $this->safeCachePut(
                    $this->portalClientsSnapshotCacheKey($normalized['request_base_url']),
                    $clientSnapshot,
                    $snapshotCacheSeconds
                );
            }

            $resolvedMacAddress = $this->matchClientMacFromSnapshot($clientSnapshot, $clientIp);
            $matched = filled($resolvedMacAddress);

            $this->safeCachePut($cacheKey, [
                'mac_address' => $resolvedMacAddress,
                'error_code' => $matched ? null : 'not_found',
            ], $matched ? $positiveCacheSeconds : $missCacheSeconds);

            Log::info('Portal Omada MAC lookup completed.', [
                'request_id' => $requestId,
                'client_ip' => $clientIp,
                'matched' => $matched,
                'cache_hit' => $snapshotCacheHit,
                'cache_type' => $snapshotCacheHit ? 'client_snapshot' : 'miss',
                'login_ms' => $loginDurationMs,
                'clients_fetch_ms' => $clientsFetchDurationMs,
                'client_count' => is_countable($clientSnapshot) ? count($clientSnapshot) : null,
                'total_ms' => $this->elapsedMilliseconds($lookupStartedAt),
            ]);

            return [
                'status' => $matched ? 'resolved' : 'retryable',
                'resolution_source' => $matched
                    ? ($snapshotCacheHit ? 'omada_snapshot' : 'omada')
                    : 'omada_not_found',
                'mac_address' => $resolvedMacAddress,
                'error_code' => $matched ? null : 'not_found',
                'retry_after_ms' => $matched ? 0 : $retryAfterMs,
            ];
        } catch (Throwable $exception) {
            $errorCode = $this->classifyOmadaException($exception);

            Log::warning('Portal Omada MAC lookup failed.', [
                'request_id' => $requestId,
                'client_ip' => $clientIp,
                'login_ms' => $loginDurationMs,
                'clients_fetch_ms' => $clientsFetchDurationMs,
                'total_ms' => $this->elapsedMilliseconds($lookupStartedAt),
                'error_code' => $errorCode,
                'error' => $exception->getMessage(),
            ]);

            return [
                'status' => in_array($errorCode, ['timeout', 'not_found'], true) ? 'retryable' : 'failed',
                'resolution_source' => 'omada_error',
                'mac_address' => null,
                'error_code' => $errorCode,
                'retry_after_ms' => $retryAfterMs,
            ];
        }
    }

    private function extractClientsFromPayload(array $payload): array
    {
        foreach ([
            'result.data',
            'result.rows',
            'result.clients',
            'result.list',
            'data',
            'rows',
        ] as $path) {
            $value = Arr::get($payload, $path);

            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        $result = Arr::get($payload, 'result');

        return is_array($result) && array_is_list($result) ? $result : [];
    }

    private function extractSitesFromPayload(array $payload): array
    {
        foreach ([
            'result.data',
            'result.rows',
            'result.sites',
            'result.list',
            'data',
            'rows',
        ] as $path) {
            $value = Arr::get($payload, $path);

            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        $result = Arr::get($payload, 'result');

        return is_array($result) && array_is_list($result) ? $result : [];
    }

    public function syncAccessPoints(ControllerSetting $settings): array
    {
        $normalized = $this->normalizeSettings($settings);
        $client = $this->authenticatedClient($normalized, 'AP sync');
        $syncedAt = now();

        $adoptedDevices = $this->fetchDeviceList($client, '/api/v2/grid/devices/adopted');
        $pendingDevices = $this->fetchDeviceList($client, '/api/v2/grid/devices/pending');

        $created = 0;
        $updated = 0;
        $claimed = 0;
        $pending = 0;

        foreach ($adoptedDevices as $device) {
            $result = $this->upsertAccessPoint($device, AccessPoint::CLAIM_STATUS_CLAIMED, $normalized, $syncedAt);

            if (! $result) {
                continue;
            }

            $claimed++;
            $result['was_created'] ? $created++ : $updated++;
        }

        foreach ($pendingDevices as $device) {
            $result = $this->upsertAccessPoint($device, AccessPoint::CLAIM_STATUS_PENDING, $normalized, $syncedAt);

            if (! $result) {
                continue;
            }

            $pending++;
            $result['was_created'] ? $created++ : $updated++;
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'claimed' => $claimed,
            'pending' => $pending,
            'total' => count($adoptedDevices) + count($pendingDevices),
        ];
    }

    public function syncSites(ControllerSetting $settings): array
    {
        $sites = $this->getSites($settings);
        $created = 0;
        $updated = 0;

        foreach ($sites as $sitePayload) {
            $siteName = $this->resolveOmadaSiteName($sitePayload);

            if ($siteName === null) {
                continue;
            }

            $omadaSiteId = $this->resolveOmadaSiteIdentifier($sitePayload);

            $site = Site::query()
                ->where(function ($query) use ($omadaSiteId, $siteName) {
                    if ($omadaSiteId) {
                        $query->where('omada_site_id', $omadaSiteId)
                            ->orWhere('name', $siteName);

                        return;
                    }

                    $query->where('name', $siteName);
                })
                ->first();

            if (! $site) {
                Site::query()->create([
                    'name' => $siteName,
                    'slug' => $this->uniqueSiteSlug($siteName),
                    'omada_site_id' => $omadaSiteId,
                ]);
                $created++;

                continue;
            }

            $site->forceFill([
                'name' => $siteName,
                'omada_site_id' => $omadaSiteId ?? $site->omada_site_id,
            ])->save();

            $updated++;
        }

        return [
            'total' => count($sites),
            'created' => $created,
            'updated' => $updated,
        ];
    }

    public function getSites(ControllerSetting $settings): array
    {
        $normalized = $this->normalizeSettings($settings);

        // Try OpenAPI first if credentials are available
        if ($this->hasOpenApiCredentials($normalized)) {
            try {
                $this->requestOpenApiAccessToken($normalized, $this->extractControllerInfo(
                    $this->request($this->client($normalized), 'get', '/api/info')
                )['omadac_id']);

                $openApi = $this->openApiAuthenticatedClient($normalized);
                $payload = $this->request($openApi['client'], 'get', "/openapi/v1/{$openApi['controller_id']}/sites", [
                    'page' => 1,
                    'pageSize' => 1000,
                ]);
                return $this->extractSitesFromPayload($payload);
            } catch (Throwable $e) {
                Log::warning('Omada getSites OpenAPI failed, trying legacy', ['error' => $e->getMessage()]);
            }
        }

        // Fallback to legacy API
        $client = $this->authenticatedClient($normalized, 'sites fetch');

        try {
            $payload = $this->request($client, 'get', '/api/v2/controller/sites');
            return $this->extractSitesFromPayload($payload);
        } catch (Throwable $e) {
            // Try alternative endpoint
            try {
                $payload = $this->request($client, 'get', '/api/v2/sites');
                return $this->extractSitesFromPayload($payload);
            } catch (Throwable $e2) {
                Log::error('Omada getSites failed on both legacy endpoints', [
                    'error1' => $e->getMessage(),
                    'error2' => $e2->getMessage(),
                    'controller_url' => $normalized['base_url'],
                ]);
                throw $e2;
            }
        }
    }

    public function adoptDevice(ControllerSetting $settings, string $deviceMac): array
    {
        $normalized = $this->normalizeSettings($settings);
        $client = $this->authenticatedClient($normalized, 'device adoption');

        $response = $this->request($client, 'post', '/api/v2/grid/devices/adopt', [
            'mac' => $deviceMac,
        ]);

        return $response;
    }

    public function authorizeClient(ControllerSetting $settings, WifiSession $session): array
    {
        $normalized = $this->normalizeSettings($settings);
        $hotspotSession = $this->hotspotAuthenticatedClient($normalized);

        if (! $session->end_time) {
            throw new RuntimeException('Session end time is missing, so Omada authorization expiry cannot be calculated.');
        }

        return $this->submitExtPortalAuth($hotspotSession, $normalized, $session, $session->end_time);
    }

    public function deauthorizeClient(ControllerSetting $settings, WifiSession $session): array
    {
        $normalized = $this->normalizeSettings($settings);
        $siteId = $this->resolveOpenApiSiteIdentifier($settings, $session);
        $clientMac = $this->normalizeMacForPath($session->mac_address);

        if (blank($siteId)) {
            throw new RuntimeException('Omada deauthorization requires a site identifier.');
        }

        if (! $this->hasOpenApiCredentials($normalized)) {
            throw new RuntimeException('Omada deauthorization requires OpenAPI client credentials.');
        }

        $openApi = $this->openApiAuthenticatedClient($normalized);

        $unauthResponse = $this->request(
            $openApi['client'],
            'post',
            "/openapi/v1/{$openApi['controller_id']}/sites/{$siteId}/hotspot/clients/{$clientMac}/unauth"
        );

        try {
            $this->request(
                $openApi['client'],
                'post',
                "/openapi/v1/{$openApi['controller_id']}/sites/{$siteId}/clients/{$clientMac}/disconnect"
            );
        } catch (RuntimeException $exception) {
            if (! str_contains($exception->getMessage(), 'This client does not exist')) {
                throw $exception;
            }
        }

        return $unauthResponse;
    }

    private function authenticatedClient(array $settings, string $purpose, ?array $timeoutProfile = null): PendingRequest
    {
        if (! $this->hasLegacyCredentials($settings)) {
            throw new RuntimeException("{$purpose} currently requires a local controller username/password. OpenAPI client credentials are only wired for connection testing right now.");
        }

        $client = $this->client($settings, $timeoutProfile);

        try {
            $loginResponse = $client->post('/api/v2/login', [
                'name' => $settings['username'],
                'password' => $settings['password'],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Omada login request failed.', [
                'purpose' => $purpose,
                'base_url' => $settings['base_url'],
                'verify_ssl' => $this->shouldVerifySsl(),
                'timeout_profile' => $timeoutProfile,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Omada login connection failed. Check controller SSL settings and connectivity.', previous: $exception);
        }

        $payload = $this->decodeResponse($loginResponse->body());

        if (($payload['errorCode'] ?? null) !== 0) {
            throw new RuntimeException(Arr::get($payload, 'msg', 'Omada login failed.'));
        }

        return $client;
    }

    private function hotspotAuthenticatedClient(array $settings): array
    {
        if (! $this->hasHotspotCredentials($settings)) {
            throw new RuntimeException('Hotspot operator credentials are required for Omada client authorization.');
        }

        $client = $this->client($settings);
        $info = $this->extractControllerInfo(
            $this->request($client, 'get', '/api/info')
        );

        if (blank($info['omadac_id'])) {
            throw new RuntimeException('Omada controller ID is missing from /api/info, so hotspot authorization cannot proceed.');
        }

        $loginResponse = $client->post("/{$info['omadac_id']}/api/v2/hotspot/login", [
            'name' => $settings['hotspot_operator_username'],
            'password' => $settings['hotspot_operator_password'],
        ]);

        if ($loginResponse->failed()) {
            throw new RuntimeException("Omada request failed for [/{$info['omadac_id']}/api/v2/hotspot/login] with HTTP {$loginResponse->status()}.");
        }

        $payload = $this->decodeResponse($loginResponse->body());

        if (($payload['errorCode'] ?? null) !== 0) {
            throw new RuntimeException(Arr::get($payload, 'msg', 'Omada hotspot operator login failed.'));
        }

        $csrfToken = Arr::get($payload, 'result.token');

        if (! is_string($csrfToken) || trim($csrfToken) === '') {
            throw new RuntimeException('Omada hotspot operator login succeeded without returning a CSRF token.');
        }

        return [
            'controller_id' => $info['omadac_id'],
            'client' => $client->withHeaders([
                'Csrf-Token' => $csrfToken,
            ]),
        ];
    }

    private function submitExtPortalAuth(array $hotspotSession, array $settings, WifiSession $session, Carbon $time): array
    {
        $site = $session->site?->name
            ?? $session->getAttribute('site_name')
            ?? $settings['site_identifier']
            ?? $settings['site_name'];

        if (blank($session->mac_address) || blank($session->ap_mac) || blank($session->ssid_name) || $session->radio_id === null || blank($site)) {
            throw new RuntimeException('Omada authorization requires client MAC, AP MAC, SSID, radio ID, and site.');
        }

        return $this->request(
            $hotspotSession['client'],
            'post',
            "/{$hotspotSession['controller_id']}/api/v2/hotspot/extPortal/auth",
            [
                'authType' => 4,
                'clientMac' => strtoupper($session->mac_address),
                'apMac' => strtoupper($session->ap_mac),
                'ssidName' => $session->ssid_name,
                'radioId' => $session->radio_id,
                'site' => $site,
                'time' => $this->toOmadaEpochMicros($time),
            ]
        );
    }

    private function openApiAuthenticatedClient(array $settings): array
    {
        $client = $this->client($settings);
        $info = $this->extractControllerInfo(
            $this->request($client, 'get', '/api/info')
        );

        if (blank($info['omadac_id'])) {
            throw new RuntimeException('Omada controller ID is missing from /api/info, so OpenAPI authentication cannot proceed.');
        }

        $token = $this->requestOpenApiAccessToken($settings, $info['omadac_id']);

        return [
            'controller_id' => $info['omadac_id'],
            'client' => $client->withHeaders([
                'Authorization' => "AccessToken={$token}",
            ]),
        ];
    }

    private function requestOpenApiAccessToken(array $settings, ?string $omadacId): string
    {
        if (! $this->hasOpenApiCredentials($settings)) {
            throw new RuntimeException('OpenAPI client ID and client secret are required.');
        }

        if (blank($omadacId)) {
            throw new RuntimeException('Omada controller ID is missing from /api/info, so OpenAPI authentication cannot proceed.');
        }

        $payload = $this->request(
            $this->client($settings),
            'post',
            '/openapi/authorize/token?grant_type=client_credentials',
            [
                'omadacId' => $omadacId,
                'client_id' => $settings['api_client_id'],
                'client_secret' => $settings['api_client_secret'],
            ]
        );

        $token = Arr::get($payload, 'result.accessToken');

        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Omada returned a successful OpenAPI response without an access token.');
        }

        return $token;
    }

    private function client(array $settings, ?array $timeoutProfile = null): PendingRequest
    {
        $timeout = $timeoutProfile['timeout'] ?? 20;
        $connectTimeout = $timeoutProfile['connect_timeout'] ?? 10;
        $baseUrl = $settings['request_base_url'] ?? $settings['base_url'];

        $client = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withHeaders([
                'Referer' => $baseUrl,
                'Origin' => $baseUrl,
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->withOptions([
                'cookies' => new CookieJar,
            ]);

        return $this->shouldVerifySsl()
            ? $client
            : $client->withoutVerifying();
    }

    private function request(PendingRequest $client, string $method, string $uri, array $payload = []): array
    {
        $requestStart = microtime(true);
        $response = $client->{$method}($uri, $payload);
        $requestDuration = microtime(true) - $requestStart;

        Log::info('Omada API request timing', [
            'method' => strtoupper($method),
            'uri' => $uri,
            'duration_ms' => (int) round($requestDuration * 1000),
            'status' => $response->status(),
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Omada request failed for [{$uri}] with HTTP {$response->status()}.");
        }

        $decoded = $this->decodeResponse($response->body());

        if (array_key_exists('errorCode', $decoded) && $decoded['errorCode'] !== 0) {
            throw new RuntimeException(Arr::get($decoded, 'msg', "Omada request failed for [{$uri}]."));
        }

        return $decoded;
    }

    private function fetchDeviceList(PendingRequest $client, string $uri): array
    {
        $payload = $this->request($client, 'get', $uri);

        foreach ([
            'result.data',
            'result.rows',
            'result.devices',
            'result.list',
            'data',
            'rows',
        ] as $path) {
            $value = Arr::get($payload, $path);

            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        $result = Arr::get($payload, 'result');

        return is_array($result) && array_is_list($result) ? $result : [];
    }

    private function upsertAccessPoint(array $device, string $claimStatus, array $settings, Carbon $syncedAt): ?array
    {
        $macAddress = $this->normalizeMac($this->firstFilled($device, [
            'mac',
            'macAddress',
        ]));

        if ($macAddress === null) {
            return null;
        }

        $omadaDeviceId = $this->stringOrNull($this->firstFilled($device, [
            'deviceId',
            'id',
            'device_id',
        ]));

        $accessPoint = AccessPoint::query()
            ->where(function ($query) use ($omadaDeviceId, $macAddress): void {
                if ($omadaDeviceId) {
                    $query->where('omada_device_id', $omadaDeviceId)
                        ->orWhere('mac_address', $macAddress);

                    return;
                }

                $query->where('mac_address', $macAddress);
            })
            ->first() ?? new AccessPoint;

        $wasCreated = ! $accessPoint->exists;
        $site = $this->resolveSite(
            $this->stringOrNull($this->firstFilled($device, [
                'siteName',
                'site',
            ])) ?? $settings['site_name']
        );

        $accessPoint->fill([
            'site_id' => $site?->id,
            'serial_number' => $this->stringOrNull($this->firstFilled($device, [
                'sn',
                'serialNumber',
                'serial_number',
            ])) ?? $accessPoint->serial_number,
            'omada_device_id' => $omadaDeviceId,
            'name' => $this->stringOrNull($this->firstFilled($device, [
                'name',
                'displayName',
                'deviceName',
            ])) ?? ($accessPoint->name ?: "AP {$macAddress}"),
            'mac_address' => $macAddress,
            'vendor' => $this->stringOrNull($this->firstFilled($device, [
                'vendor',
                'manufacturer',
            ])) ?? ($accessPoint->vendor ?: 'TP-Link'),
            'model' => $this->stringOrNull($this->firstFilled($device, [
                'model',
                'deviceModel',
            ])) ?? $accessPoint->model,
            'ip_address' => $this->stringOrNull($this->firstFilled($device, [
                'ip',
                'ipAddress',
            ])) ?? $accessPoint->ip_address,
            'claim_status' => $claimStatus,
            'claimed_at' => $claimStatus === AccessPoint::CLAIM_STATUS_CLAIMED
                ? ($accessPoint->claimed_at ?? $syncedAt)
                : null,
            'last_synced_at' => $syncedAt,
            'is_online' => $this->resolveOnlineState($device),
            'last_seen_at' => $this->resolveLastSeenAt($device) ?? $accessPoint->last_seen_at,
        ]);

        if ($wasCreated) {
            $accessPoint->custom_ssid = 'KennFi Lab';
            $accessPoint->allow_client_pause = true;
            $accessPoint->block_tethering = true;
            $accessPoint->is_portal_enabled = true;
        }

        $accessPoint->save();

        return [
            'was_created' => $wasCreated,
            'access_point_id' => $accessPoint->id,
        ];
    }

    private function resolveOnlineState(array $device): bool
    {
        $value = $this->firstFilled($device, [
            'isOnline',
            'connected',
            'status',
            'statusCategory',
        ]);

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $status = strtolower(trim((string) $value));

        return in_array($status, ['connected', 'online', 'normal', 'up'], true);
    }

    private function resolveLastSeenAt(array $device): ?Carbon
    {
        $value = $this->firstFilled($device, [
            'lastSeenAt',
            'lastSeen',
            'latestSeen',
            'lastSeenTime',
        ]);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;

            if ($timestamp > 9999999999) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return Carbon::createFromTimestamp($timestamp);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveSite(?string $siteName): ?Site
    {
        if (blank($siteName)) {
            return null;
        }

        $site = Site::query()->firstWhere('name', $siteName);

        if ($site) {
            return $site;
        }

        $baseSlug = Str::slug($siteName) ?: 'location';
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

    private function resolveOmadaSiteIdentifier(array $payload): ?string
    {
        return $this->stringOrNull($this->firstFilled($payload, [
            'siteId',
            'site_id',
            'id',
            'key',
        ]));
    }

    private function resolveOmadaSiteName(array $payload): ?string
    {
        return $this->stringOrNull($this->firstFilled($payload, [
            'name',
            'siteName',
            'displayName',
        ]));
    }

    private function uniqueSiteSlug(string $siteName): string
    {
        $baseSlug = Str::slug($siteName) ?: 'location';
        $slug = $baseSlug;
        $suffix = 2;

        while (Site::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function normalizeSettings(ControllerSetting $settings): array
    {
        if (blank($settings->base_url)) {
            throw new RuntimeException('Controller URL is missing.');
        }

        $configuredBaseUrl = rtrim((string) $settings->base_url, '/');

        return [
            'controller_name' => $settings->controller_name ?: 'Primary Omada Controller',
            'base_url' => $configuredBaseUrl,
            'request_base_url' => $this->resolveRequestBaseUrl($configuredBaseUrl),
            'username' => $this->stringOrNull($settings->username),
            'password' => $this->stringOrNull($settings->password),
            'hotspot_operator_username' => $this->stringOrNull($settings->hotspot_operator_username)
                ?? $this->stringOrNull($settings->username),
            'hotspot_operator_password' => $this->stringOrNull($settings->hotspot_operator_password)
                ?? $this->stringOrNull($settings->password),
            'api_client_id' => $this->stringOrNull($settings->api_client_id),
            'api_client_secret' => $this->stringOrNull($settings->api_client_secret),
            'site_identifier' => $this->stringOrNull($settings->site_identifier),
            'site_name' => $settings->site_name ?: null,
        ];
    }

    private function hasLegacyCredentials(array $settings): bool
    {
        return filled($settings['username'] ?? null) && filled($settings['password'] ?? null);
    }

    private function hasOpenApiCredentials(array $settings): bool
    {
        return filled($settings['api_client_id'] ?? null) && filled($settings['api_client_secret'] ?? null);
    }

    private function hasHotspotCredentials(array $settings): bool
    {
        return filled($settings['hotspot_operator_username'] ?? null) && filled($settings['hotspot_operator_password'] ?? null);
    }

    private function extractControllerInfo(array $payload): array
    {
        return [
            'controller_name' => $this->stringOrNull(
                Arr::get($payload, 'result.controllerName')
                    ?? Arr::get($payload, 'result.omadacName')
                    ?? Arr::get($payload, 'controllerName')
                    ?? Arr::get($payload, 'omadacName')
            ),
            'version' => $this->stringOrNull(
                Arr::get($payload, 'result.controllerVer')
                    ?? Arr::get($payload, 'omadacVersion')
                    ?? Arr::get($payload, 'controllerVer')
            ),
            'api_version' => $this->stringOrNull(
                Arr::get($payload, 'result.apiVer')
                    ?? Arr::get($payload, 'apiVer')
            ),
            'omadac_id' => $this->stringOrNull(
                Arr::get($payload, 'result.omadacId')
                    ?? Arr::get($payload, 'omadacId')
            ),
        ];
    }

    private function shouldVerifySsl(): bool
    {
        return (bool) config('services.omada.verify_ssl', true);
    }

    private function normalizeMac(mixed $value): ?string
    {
        $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string) $value) ?? '');

        if (strlen($mac) !== 12) {
            return null;
        }

        return implode(':', str_split($mac, 2));
    }

    private function firstFilled(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function decodeResponse(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Omada returned an invalid JSON response.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Omada returned an unexpected response payload.');
        }

        return $decoded;
    }

    private function toOmadaEpochMicros(Carbon $time): int
    {
        return ((int) $time->getTimestamp()) * 1000000 + ((int) $time->micro);
    }

    private function resolveOpenApiSiteIdentifier(ControllerSetting $settings, WifiSession $session): ?string
    {
        return $this->stringOrNull($session->site?->slug)
            ?? $this->stringOrNull($session->site?->name)
            ?? $this->stringOrNull($settings->site_identifier);
    }

    private function portalMacLookupTimeoutProfile(): array
    {
        return [
            'connect_timeout' => max(1, (int) config('portal.omada_connect_timeout_seconds', 1)),
            'timeout' => max(1, (int) config('portal.omada_timeout_seconds', 4)),
        ];
    }

    private function portalMacLookupCacheKey(string $baseUrl, string $clientIp): string
    {
        return 'portal:omada:mac_lookup:' . sha1($baseUrl . '|' . $clientIp);
    }

    private function portalClientsSnapshotCacheKey(string $baseUrl): string
    {
        return 'portal:omada:clients_snapshot:' . sha1($baseUrl);
    }

    private function matchClientMacFromSnapshot(array $clientSnapshot, string $clientIp): ?string
    {
        foreach ($clientSnapshot as $clientData) {
            $clientIpFromApi = $this->firstFilled($clientData, [
                'ip',
                'ipAddress',
                'clientIp',
            ]);

            if ($clientIpFromApi && $clientIpFromApi === $clientIp) {
                return $this->normalizeMac($this->firstFilled($clientData, [
                    'mac',
                    'macAddress',
                    'clientMac',
                ]));
            }
        }

        return null;
    }

    private function resolveRequestBaseUrl(string $configuredBaseUrl): string
    {
        $internalBaseUrl = trim((string) config('services.omada.internal_base_url', ''));

        return $internalBaseUrl !== '' ? rtrim($internalBaseUrl, '/') : $configuredBaseUrl;
    }

    private function classifyOmadaException(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'ssl certificate') || str_contains($message, 'curl error 60')) {
            return 'omada_ssl';
        }

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'timeout';
        }

        if (str_contains($message, 'http 401') || str_contains($message, 'http 403')
            || str_contains($message, 'login failed') || str_contains($message, 'unauthorized')) {
            return 'auth';
        }

        return 'omada_error';
    }

    private function cacheStore(): CacheRepository
    {
        return Cache::store((string) config('portal.cache_store', config('cache.default', 'database')));
    }

    private function safeCacheGet(string $key): mixed
    {
        try {
            return $this->cacheStore()->get($key);
        } catch (Throwable $exception) {
            Log::warning('Portal Omada cache read failed.', [
                'cache_key' => $key,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function safeCachePut(string $key, mixed $value, int $seconds): void
    {
        if ($seconds < 1) {
            return;
        }

        try {
            $this->cacheStore()->put($key, $value, now()->addSeconds($seconds));
        } catch (Throwable $exception) {
            Log::warning('Portal Omada cache write failed.', [
                'cache_key' => $key,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function normalizeMacForPath(?string $macAddress): string
    {
        $normalized = $this->normalizeMac($macAddress);

        if ($normalized === null) {
            throw new RuntimeException('Omada deauthorization requires a valid client MAC address.');
        }

        return str_replace(':', '-', $normalized);
    }
}
