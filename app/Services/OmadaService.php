<?php

namespace App\Services;

use App\Exceptions\OmadaOperationException;
use App\Models\AccessPoint;
use App\Models\ControllerSetting;
use App\Models\Site;
use App\Models\WifiSession;
use App\Support\MacAddress;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OmadaService
{
    public function __construct(
        private readonly AccessPointHealthService $accessPointHealthService,
    ) {
    }

    public function testConnection(ControllerSetting $settings): array
    {
        $normalized = $this->normalizeSettings($settings);

        if (! $this->hasOpenApiCredentials($normalized)) {
            throw new RuntimeException('OpenAPI client credentials are required before testing the controller connection.');
        }

        $info = $this->extractControllerInfo(
            $this->request($this->client($normalized), 'get', '/api/info')
        );

        $this->requestOpenApiAccessToken($normalized, $info['omadac_id']);

        return [
            'controller_name' => $info['controller_name'] ?? $normalized['controller_name'],
            'version' => $info['version'],
            'api_version' => $info['api_version'],
        ];
    }

    public function listAuthorizedClients(ControllerSetting $settings): array
    {
        $normalized = $this->normalizeSettings($settings);

        if (! $this->hasOpenApiCredentials($normalized)) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                'OpenAPI client credentials are required to list authorized Omada clients for reconciliation.'
            );
        }

        $clients = $this->fetchOpenApiClientsAcrossSites($normalized);

        return array_values(array_filter(array_map(function (array $client): ?array {
            $normalizedClient = $this->normalizeControllerClient($client);

            if (! $normalizedClient || ! $normalizedClient['authorized']) {
                return null;
            }

            return $normalizedClient;
        }, $clients)));
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
        $syncedAt = now();

        if (! $this->hasOpenApiCredentials($normalized)) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                'OpenAPI client credentials are required for Omada AP sync.'
            );
        }

        [$adoptedDevices, $pendingDevices] = $this->fetchAccessPointsViaOpenApi($normalized);

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

        $this->accessPointHealthService->noteSyncHeartbeat($syncedAt);

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

        if (! $this->hasOpenApiCredentials($normalized)) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                'OpenAPI client credentials are required to fetch Omada sites.'
            );
        }

        $openApi = $this->openApiAuthenticatedClient($normalized);
        $payload = $this->request($openApi['client'], 'get', "/openapi/v1/{$openApi['controller_id']}/sites", [
            'page' => 1,
            'pageSize' => 1000,
        ]);

        return $this->extractSitesFromPayload($payload);
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
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_VALIDATION,
                'Session end time is missing, so Omada authorization expiry cannot be calculated.'
            );
        }

        return $this->submitExtPortalAuth($hotspotSession, $normalized, $session, $session->end_time);
    }

    public function authorizeClientForManualSession(ControllerSetting $settings, WifiSession $session): array
    {
        if (! $session->end_time) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_VALIDATION,
                'Session end time is missing, so Omada authorization expiry cannot be calculated.'
            );
        }

        $remainingSeconds = now()->diffInSeconds($session->end_time, false);

        if ($remainingSeconds <= 0) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_VALIDATION,
                'The selected plan has already expired for this manual authorization request.'
            );
        }

        $expiresAt = now()->addSeconds($remainingSeconds);
        $normalized = $this->normalizeSettings($settings);
        $hotspotSession = $this->hotspotAuthenticatedClient($normalized);

        return $this->submitExtPortalAuth($hotspotSession, $normalized, $session, $expiresAt);
    }

    public function classifyFailure(Throwable $exception): string
    {
        return $this->classifyOmadaException($exception);
    }

    public function deauthorizeClient(ControllerSetting $settings, WifiSession $session): array
    {
        $siteId = $this->resolveOpenApiSiteIdentifier($settings, $session);
        return $this->deauthorizeClientByMac($settings, $session->mac_address, $siteId);
    }

    public function deauthorizeClientByMac(ControllerSetting $settings, string $macAddress, ?string $siteIdentifier = null): array
    {
        $normalized = $this->normalizeSettings($settings);
        $siteContext = $this->resolveOpenApiSiteContextFromValue($settings, $siteIdentifier);
        $siteId = $siteContext['site_id'];
        $clientMac = $this->normalizeMacForPath($macAddress);

        if (blank($siteId)) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_VALIDATION,
                'Omada deauthorization requires a site identifier.'
            );
        }

        if (! $this->hasOpenApiCredentials($normalized)) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                'Omada deauthorization requires OpenAPI client credentials.'
            );
        }

        $openApi = $this->openApiAuthenticatedClient($normalized);

        Log::info('Omada client deauthorization requested.', [
            'site_identifier' => $siteId,
            'mac_address' => MacAddress::normalizeForStorage($macAddress),
        ]);

        try {
            return $this->deauthorizeClientWithOpenApiSession($openApi, $siteId, $clientMac);
        } catch (OmadaOperationException $exception) {
            $refreshedSiteId = $this->resolveAccessibleOpenApiSiteIdentifier(
                $settings,
                $siteContext['site'],
                $siteContext['requested_identifier']
            );

            if (! $this->shouldRetryDeauthorizationWithRefreshedSiteId($exception, $siteId, $refreshedSiteId)) {
                throw $exception;
            }

            $this->persistResolvedOmadaSiteIdentifier($siteContext['site'], $refreshedSiteId);

            Log::warning('Omada deauthorization retried after refreshing site identifier.', [
                'mac_address' => MacAddress::normalizeForStorage($macAddress),
                'stale_site_identifier' => $siteId,
                'refreshed_site_identifier' => $refreshedSiteId,
            ]);

            return $this->deauthorizeClientWithOpenApiSession($openApi, $refreshedSiteId, $clientMac);
        }
    }

    public function disconnectClient(ControllerSetting $settings, string $macAddress, ?string $siteIdentifier = null): array
    {
        $normalized = $this->normalizeSettings($settings);
        $siteId = $this->resolveOpenApiSiteIdentifierFromValue($settings, $siteIdentifier);
        $clientMac = $this->normalizeMacForPath($macAddress);

        if (blank($siteId)) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_VALIDATION,
                'Omada disconnect requires a site identifier.'
            );
        }

        if (! $this->hasOpenApiCredentials($normalized)) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                'Omada disconnect requires OpenAPI client credentials.'
            );
        }

        return $this->disconnectClientWithOpenApiSession($this->openApiAuthenticatedClient($normalized), $siteId, $clientMac);
    }

    public function inspectClientAuthorization(ControllerSetting $settings, WifiSession $session): array
    {
        $normalized = $this->normalizeSettings($settings);

        if (! $this->hasOpenApiCredentials($normalized)) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                'OpenAPI client credentials are required to inspect client authorization state.'
            );
        }

        $normalizedMac = $this->normalizeMac($session->mac_address);

        if (! $normalizedMac) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_VALIDATION,
                'Client MAC address is required to inspect controller authorization state.'
            );
        }

        $openApi = $this->openApiAuthenticatedClient($normalized);
        $siteId = $this->resolveOpenApiSiteIdentifier($settings, $session);
        $matchedClient = $siteId
            ? $this->fetchOpenApiClientByMac($openApi, $siteId, $normalizedMac)
            : null;

        if (! $matchedClient) {
            $matchedClient = $this->matchClientRecordByMac(
                $this->fetchOpenApiClientsAcrossSites($normalized),
                $normalizedMac
            );
        }

        if (! $matchedClient) {
            return [
                'found' => false,
                'authorized' => false,
                'connected' => false,
                'raw_status' => null,
                'raw_portal_status' => null,
                'source' => 'controller_clients',
            ];
        }

        $normalizedClient = $this->normalizeControllerClient($matchedClient);

        if (! $normalizedClient) {
            return [
                'found' => false,
                'authorized' => false,
                'connected' => false,
                'raw_status' => null,
                'raw_portal_status' => null,
                'source' => 'controller_clients',
            ];
        }

        return [
            'found' => true,
            'authorized' => $normalizedClient['authorized'],
            'connected' => $normalizedClient['connected'],
            'raw_status' => $normalizedClient['raw_status'],
            'raw_portal_status' => $normalizedClient['raw_portal_status'],
            'source' => 'controller_clients',
        ];
    }

    private function fetchOpenApiClientsAcrossSites(array $settings): array
    {
        $openApi = $this->openApiAuthenticatedClient($settings);
        $clients = [];

        foreach ($this->fetchOpenApiSites($openApi) as $sitePayload) {
            $siteId = $this->resolveOmadaSiteIdentifier($sitePayload);
            $siteName = $this->resolveOmadaSiteName($sitePayload);

            if (! $siteId || ! $siteName) {
                continue;
            }

            foreach ($this->fetchOpenApiSiteClients($openApi, $siteId) as $client) {
                $clients[] = $this->withClientSiteContext($client, $siteName, $siteId);
            }
        }

        return $clients;
    }

    private function fetchOpenApiSiteClients(array $openApi, string $siteId): array
    {
        return $this->fetchOpenApiPaginatedClientList(
            $openApi,
            "/openapi/v1/{$openApi['controller_id']}/sites/{$siteId}/clients"
        );
    }

    private function fetchOpenApiClientByMac(array $openApi, string $siteId, string $macAddress): ?array
    {
        try {
            $payload = $this->request(
                $openApi['client'],
                'get',
                "/openapi/v1/{$openApi['controller_id']}/sites/{$siteId}/clients/{$this->normalizeMacForPath($macAddress)}"
            );
        } catch (OmadaOperationException $exception) {
            if ($exception->category === OmadaOperationException::CATEGORY_CONTROLLER
                && str_contains(strtolower($exception->getMessage()), 'does not exist')) {
                return null;
            }

            throw $exception;
        }

        $client = Arr::get($payload, 'result');

        return is_array($client)
            ? $this->withClientSiteContext($client, $this->resolveSiteNameByIdentifier($siteId), $siteId)
            : null;
    }

    private function fetchOpenApiPaginatedClientList(array $openApi, string $uri): array
    {
        $page = 1;
        $pageSize = 1000;
        $clients = [];

        do {
            $payload = $this->request(
                $openApi['client'],
                'get',
                $uri,
                [
                    'page' => $page,
                    'pageSize' => $pageSize,
                ]
            );

            $pageClients = $this->extractClientsFromPayload($payload);
            $clients = [...$clients, ...$pageClients];
            $totalRows = (int) (Arr::get($payload, 'result.totalRows') ?? count($pageClients));
            $page++;
        } while (count($pageClients) === $pageSize && count($clients) < $totalRows);

        return $clients;
    }

    private function authenticatedClient(array $settings, string $purpose, ?array $timeoutProfile = null): PendingRequest
    {
        if (! $this->hasLegacyCredentials($settings)) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                "{$purpose} currently requires a local controller username/password. OpenAPI client credentials are only wired for connection testing right now."
            );
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

            throw $this->wrapTransportException(
                $exception,
                'Omada login connection failed. Check controller SSL settings and connectivity.'
            );
        }

        $payload = $this->decodeResponse($loginResponse->body());

        if (($payload['errorCode'] ?? null) !== 0) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_AUTHENTICATION,
                Arr::get($payload, 'msg', 'Omada login failed.')
            );
        }

        return $client;
    }

    private function hotspotAuthenticatedClient(array $settings): array
    {
        if (! $this->hasHotspotCredentials($settings)) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                'Hotspot operator credentials are required for Omada client authorization.'
            );
        }

        $client = $this->client($settings);
        $info = $this->extractControllerInfo(
            $this->request($client, 'get', '/api/info')
        );

        if (blank($info['omadac_id'])) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                'Omada controller ID is missing from /api/info, so hotspot authorization cannot proceed.'
            );
        }

        $loginResponse = $client->post("/{$info['omadac_id']}/api/v2/hotspot/login", [
            'name' => $settings['hotspot_operator_username'],
            'password' => $settings['hotspot_operator_password'],
        ]);

        if ($loginResponse->failed()) {
            throw $this->classifyHttpFailure(
                "Omada request failed for [/{$info['omadac_id']}/api/v2/hotspot/login] with HTTP {$loginResponse->status()}.",
                $loginResponse->status()
            );
        }

        $payload = $this->decodeResponse($loginResponse->body());

        if (($payload['errorCode'] ?? null) !== 0) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_AUTHENTICATION,
                Arr::get($payload, 'msg', 'Omada hotspot operator login failed.')
            );
        }

        $csrfToken = Arr::get($payload, 'result.token');

        if (! is_string($csrfToken) || trim($csrfToken) === '') {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONTROLLER,
                'Omada hotspot operator login succeeded without returning a CSRF token.'
            );
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
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_VALIDATION,
                'Omada authorization requires client MAC, AP MAC, SSID, radio ID, and site.'
            );
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
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                'Omada controller ID is missing from /api/info, so OpenAPI authentication cannot proceed.'
            );
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
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                'OpenAPI client ID and client secret are required.'
            );
        }

        if (blank($omadacId)) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONFIGURATION,
                'Omada controller ID is missing from /api/info, so OpenAPI authentication cannot proceed.'
            );
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
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONTROLLER,
                'Omada returned a successful OpenAPI response without an access token.'
            );
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
        try {
            $response = $client->{$method}($uri, $payload);
        } catch (Throwable $exception) {
            throw $this->wrapTransportException($exception, "Omada request failed for [{$uri}].");
        }
        $requestDuration = microtime(true) - $requestStart;

        Log::info('Omada API request timing', [
            'method' => strtoupper($method),
            'uri' => $uri,
            'duration_ms' => (int) round($requestDuration * 1000),
            'status' => $response->status(),
        ]);

        if ($response->failed()) {
            throw $this->classifyHttpFailure(
                "Omada request failed for [{$uri}] with HTTP {$response->status()}.",
                $response->status()
            );
        }

        $decoded = $this->decodeResponse($response->body());

        if (array_key_exists('errorCode', $decoded) && $decoded['errorCode'] !== 0) {
            throw new OmadaOperationException(
                OmadaOperationException::CATEGORY_CONTROLLER,
                Arr::get($decoded, 'msg', "Omada request failed for [{$uri}].")
            );
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

    private function fetchAccessPointsViaOpenApi(array $settings): array
    {
        $openApi = $this->openApiAuthenticatedClient($settings);
        $sites = $this->fetchOpenApiSites($openApi);
        $adoptedDevices = [];
        $pendingDevices = [];
        $pendingFingerprintIndex = [];

        foreach ($sites as $sitePayload) {
            $siteId = $this->resolveOmadaSiteIdentifier($sitePayload);
            $siteName = $this->resolveOmadaSiteName($sitePayload);

            if (! $siteId || ! $siteName) {
                continue;
            }

            foreach ($this->fetchOpenApiSiteDevices($openApi, $siteId) as $device) {
                if (! $this->isAccessPointDevice($device) || $this->isPendingOpenApiDevice($device)) {
                    continue;
                }

                $adoptedDevices[] = $this->withDeviceSiteContext($device, $siteName, $siteId);
            }

            foreach ($this->fetchOpenApiPendingDevices($openApi, $siteId) as $device) {
                if (! $this->isAccessPointDevice($device)) {
                    continue;
                }

                $fingerprint = $this->deviceFingerprint($device);

                if ($fingerprint && isset($pendingFingerprintIndex[$fingerprint])) {
                    continue;
                }

                if ($fingerprint) {
                    $pendingFingerprintIndex[$fingerprint] = true;
                }

                $pendingDevices[] = $this->withDeviceSiteContext($device, $siteName, $siteId);
            }
        }

        return [$adoptedDevices, $pendingDevices];
    }

    private function fetchOpenApiSites(array $openApi): array
    {
        $payload = $this->request(
            $openApi['client'],
            'get',
            "/openapi/v1/{$openApi['controller_id']}/sites",
            [
                'page' => 1,
                'pageSize' => 1000,
            ]
        );

        return $this->extractSitesFromPayload($payload);
    }

    private function fetchOpenApiSiteDevices(array $openApi, string $siteId): array
    {
        $payload = $this->request(
            $openApi['client'],
            'get',
            "/openapi/v1/{$openApi['controller_id']}/sites/{$siteId}/devices/all"
        );

        return $this->extractDevicesFromPayload($payload);
    }

    private function fetchOpenApiPendingDevices(array $openApi, string $siteId): array
    {
        return $this->fetchOpenApiPaginatedDeviceList(
            $openApi,
            "/openapi/v1/{$openApi['controller_id']}/sites/{$siteId}/grid/devices/pending"
        );
    }

    private function fetchOpenApiPaginatedDeviceList(array $openApi, string $uri): array
    {
        $page = 1;
        $pageSize = 1000;
        $devices = [];

        do {
            $payload = $this->request(
                $openApi['client'],
                'get',
                $uri,
                [
                    'page' => $page,
                    'pageSize' => $pageSize,
                ]
            );

            $pageDevices = $this->extractDevicesFromPayload($payload);
            $devices = [...$devices, ...$pageDevices];
            $totalRows = (int) (Arr::get($payload, 'result.totalRows') ?? count($pageDevices));
            $page++;
        } while (count($pageDevices) === $pageSize && count($devices) < $totalRows);

        return $devices;
    }

    private function extractDevicesFromPayload(array $payload): array
    {
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

    private function withDeviceSiteContext(array $device, string $siteName, string $siteId): array
    {
        $device['siteName'] = $device['siteName'] ?? $siteName;
        $device['siteId'] = $device['siteId'] ?? $siteId;

        return $device;
    }

    private function withClientSiteContext(array $client, ?string $siteName, string $siteId): array
    {
        if ($siteName) {
            $client['siteName'] = $client['siteName'] ?? $siteName;
        }

        $client['siteId'] = $client['siteId'] ?? $siteId;

        return $client;
    }

    private function deviceFingerprint(array $device): ?string
    {
        $mac = $this->normalizeMac($this->firstFilled($device, [
            'mac',
            'macAddress',
        ]));

        if ($mac) {
            return "mac:{$mac}";
        }

        $serial = $this->stringOrNull($this->firstFilled($device, [
            'sn',
            'serialNumber',
            'serial_number',
        ]));

        return $serial ? "serial:{$serial}" : null;
    }

    private function isAccessPointDevice(array $device): bool
    {
        $type = strtolower((string) ($this->firstFilled($device, [
            'type',
            'deviceType',
        ]) ?? ''));
        $model = strtolower((string) ($this->firstFilled($device, [
            'model',
            'deviceModel',
        ]) ?? ''));

        if ($type === '') {
            return str_starts_with($model, 'eap') || $model === '';
        }

        return str_contains($type, 'ap') || str_contains($model, 'eap');
    }

    private function isPendingOpenApiDevice(array $device): bool
    {
        $status = $this->firstFilled($device, [
            'status',
            'statusCategory',
        ]);
        $detailStatus = $this->firstFilled($device, [
            'detailStatus',
            'deviceDetailStatus',
        ]);

        $normalizedStatus = is_string($status) ? strtolower($status) : $status;
        $normalizedDetailStatus = is_string($detailStatus) ? strtolower($detailStatus) : $detailStatus;

        return in_array($normalizedStatus, [2, '2', 'pending'], true)
            || in_array($normalizedDetailStatus, [20, 21, '20', '21', 'pending'], true);
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
                        ->orWhere('mac_address', $macAddress)
                        ->orWhereRaw(
                            "LOWER(REPLACE(REPLACE(mac_address, ':', ''), '-', '')) = ?",
                            [$this->macLookupFingerprint($macAddress)]
                        );

                    return;
                }

                $query->where('mac_address', $macAddress)
                    ->orWhereRaw(
                        "LOWER(REPLACE(REPLACE(mac_address, ':', ''), '-', '')) = ?",
                        [$this->macLookupFingerprint($macAddress)]
                    );
            })
            ->first() ?? new AccessPoint;

        $wasCreated = ! $accessPoint->exists;
        $siteName = $this->stringOrNull($this->firstFilled($device, [
            'siteName',
            'site',
        ]));
        $siteId = $this->stringOrNull($this->firstFilled($device, [
            'siteId',
            'site_id',
        ]));
        $site = ($siteName || $siteId) ? $this->resolveSite($siteName, $siteId) : null;

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
            'adoption_state' => $accessPoint->adoption_state ?? AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'claimed_at' => $claimStatus === AccessPoint::CLAIM_STATUS_CLAIMED
                ? ($accessPoint->claimed_at ?? $syncedAt)
                : null,
            'last_synced_at' => $syncedAt,
        ]);

        $this->accessPointHealthService->applyControllerObservation(
            $accessPoint,
            $device,
            $syncedAt,
            $claimStatus,
            AccessPoint::STATUS_SOURCE_SYNC,
        );

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

    private function resolveSite(?string $siteName, ?string $omadaSiteId = null): ?Site
    {
        if (blank($siteName) && blank($omadaSiteId)) {
            return null;
        }

        $site = Site::query()
            ->when(filled($omadaSiteId), fn ($query) => $query->where('omada_site_id', $omadaSiteId))
            ->when(blank($omadaSiteId) && filled($siteName), fn ($query) => $query->where('name', $siteName))
            ->first();

        if ($site) {
            if (filled($omadaSiteId) && $site->omada_site_id !== $omadaSiteId) {
                $site->forceFill([
                    'omada_site_id' => $omadaSiteId,
                    'name' => $siteName ?: $site->name,
                ])->save();
            }

            return $site;
        }

        $siteName = $siteName ?: "Omada Site {$omadaSiteId}";
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
            'omada_site_id' => $omadaSiteId,
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
        return MacAddress::normalizeForStorage($value);
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
        return $this->resolveOpenApiSiteIdentifierFromValue(
            $settings,
            $this->stringOrNull($session->site?->slug)
            ?? $this->stringOrNull($session->site?->name)
            ?? $this->stringOrNull($settings->site_identifier)
        );
    }

    private function resolveOpenApiSiteIdentifierFromValue(ControllerSetting $settings, ?string $siteIdentifier): ?string
    {
        return $this->resolveOpenApiSiteContextFromValue($settings, $siteIdentifier)['site_id'];
    }

    /**
     * @return array{site_id:?string, site:?Site, requested_identifier:?string}
     */
    private function resolveOpenApiSiteContextFromValue(ControllerSetting $settings, ?string $siteIdentifier): array
    {
        $siteIdentifier = $this->stringOrNull($siteIdentifier);

        if ($siteIdentifier === null) {
            return [
                'site_id' => $this->stringOrNull($settings->site_identifier),
                'site' => null,
                'requested_identifier' => null,
            ];
        }

        $site = Schema::hasTable('sites')
            ? Site::query()
                ->where('slug', $siteIdentifier)
                ->orWhere('name', $siteIdentifier)
                ->orWhere('omada_site_id', $siteIdentifier)
                ->first()
            : null;

        return [
            'site_id' => $this->stringOrNull($site?->omada_site_id)
            ?? $this->stringOrNull($site?->slug)
            ?? $siteIdentifier
            ?? $this->stringOrNull($settings->site_identifier),
            'site' => $site,
            'requested_identifier' => $siteIdentifier,
        ];
    }

    private function resolveAccessibleOpenApiSiteIdentifier(
        ControllerSetting $settings,
        ?Site $site,
        ?string $requestedIdentifier
    ): ?string {
        $candidates = array_values(array_filter(array_unique([
            $this->stringOrNull($site?->name),
            $this->stringOrNull($site?->slug),
            $this->stringOrNull($site?->omada_site_id),
            $this->stringOrNull($requestedIdentifier),
        ])));

        if ($candidates === []) {
            return null;
        }

        foreach ($this->getSites($settings) as $controllerSite) {
            $liveSiteId = $this->stringOrNull($this->firstFilled($controllerSite, ['siteId', 'id']));
            $liveSiteName = $this->stringOrNull($this->firstFilled($controllerSite, ['name', 'siteName']));
            $liveSiteSlug = $liveSiteName ? Str::slug($liveSiteName) : null;

            if (in_array($liveSiteId, $candidates, true)
                || in_array($liveSiteName, $candidates, true)
                || in_array($liveSiteSlug, $candidates, true)) {
                return $liveSiteId;
            }
        }

        return null;
    }

    private function persistResolvedOmadaSiteIdentifier(?Site $site, ?string $siteIdentifier): void
    {
        if (! $site || blank($siteIdentifier) || $site->omada_site_id === $siteIdentifier) {
            return;
        }

        $previous = $site->omada_site_id;
        $site->forceFill([
            'omada_site_id' => $siteIdentifier,
        ])->save();

        Log::warning('Local Omada site identifier refreshed from controller metadata.', [
            'site_id' => $site->id,
            'site_name' => $site->name,
            'previous_omada_site_id' => $previous,
            'refreshed_omada_site_id' => $siteIdentifier,
        ]);
    }

    private function shouldRetryDeauthorizationWithRefreshedSiteId(
        OmadaOperationException $exception,
        ?string $currentSiteId,
        ?string $refreshedSiteId
    ): bool {
        if (blank($refreshedSiteId) || $refreshedSiteId === $currentSiteId) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'permissions to access this site')
            || str_contains($message, 'site does not exist')
            || str_contains($message, 'invalid site');
    }

    private function deauthorizeClientWithOpenApiSession(array $openApi, string $siteId, string $clientMac): array
    {
        $unauthResponse = $this->request(
            $openApi['client'],
            'post',
            "/openapi/v1/{$openApi['controller_id']}/sites/{$siteId}/hotspot/clients/{$clientMac}/unauth"
        );

        $this->disconnectClientWithOpenApiSession($openApi, $siteId, $clientMac);

        return $unauthResponse;
    }

    private function portalMacLookupTimeoutProfile(): array
    {
        return [
            'connect_timeout' => max(1, (int) config('portal.omada_connect_timeout_seconds', 1)),
            'timeout' => max(1, (int) config('portal.omada_timeout_seconds', 4)),
        ];
    }

    private function matchClientRecordByMac(array $clientSnapshot, string $normalizedMac): ?array
    {
        foreach ($clientSnapshot as $clientData) {
            $clientMac = MacAddress::normalizeForDisplay($this->firstFilled($clientData, [
                'mac',
                'macAddress',
                'clientMac',
            ]));

            if ($clientMac === $normalizedMac) {
                return $clientData;
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
        if ($exception instanceof OmadaOperationException) {
            return match ($exception->category) {
                OmadaOperationException::CATEGORY_TIMEOUT => 'timeout',
                OmadaOperationException::CATEGORY_AUTHENTICATION => 'auth',
                OmadaOperationException::CATEGORY_SSL => 'omada_ssl',
                default => 'omada_error',
            };
        }

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

    private function wrapTransportException(Throwable $exception, string $fallbackMessage): OmadaOperationException
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'ssl certificate') || str_contains($message, 'curl error 60')) {
            return new OmadaOperationException(
                OmadaOperationException::CATEGORY_SSL,
                $fallbackMessage,
                previous: $exception,
            );
        }

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return new OmadaOperationException(
                OmadaOperationException::CATEGORY_TIMEOUT,
                $fallbackMessage,
                previous: $exception,
            );
        }

        return new OmadaOperationException(
            OmadaOperationException::CATEGORY_CONTROLLER,
            $fallbackMessage,
            previous: $exception,
        );
    }

    private function classifyHttpFailure(string $message, int $status): OmadaOperationException
    {
        $category = match (true) {
            in_array($status, [401, 403], true) => OmadaOperationException::CATEGORY_AUTHENTICATION,
            $status >= 500 => OmadaOperationException::CATEGORY_CONTROLLER,
            default => OmadaOperationException::CATEGORY_VALIDATION,
        };

        return new OmadaOperationException($category, $message, $status);
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function disconnectClientWithOpenApiSession(array $openApi, string $siteId, string $clientMac): array
    {
        try {
            return $this->request(
                $openApi['client'],
                'post',
                "/openapi/v1/{$openApi['controller_id']}/sites/{$siteId}/clients/{$clientMac}/disconnect"
            );
        } catch (RuntimeException $exception) {
            if (! str_contains($exception->getMessage(), 'This client does not exist')) {
                throw $exception;
            }

            return [
                'errorCode' => 0,
                'msg' => 'Client already absent from controller.',
            ];
        }
    }

    public function normalizeControllerClient(array $client): ?array
    {
        $macAddress = MacAddress::normalizeForStorage($this->firstFilled($client, [
            'mac',
            'macAddress',
            'clientMac',
        ]));

        if ($macAddress === null) {
            return null;
        }

        $statusValue = $this->firstFilled($client, [
            'status',
            'state',
            'statusText',
            'wireless.status',
            'active',
            'isActive',
        ]);
        $rawStatus = $this->normalizeClientStatus($statusValue);
        $portalStatusValue = $this->firstFilled($client, [
            'portalAuthStatus',
            'portalStatus',
            'authenticationStatus',
            'authStatus',
        ]);
        $rawPortalStatus = $this->normalizeClientPortalStatus($portalStatusValue);

        $authorized = in_array($this->firstFilled($client, [
            'authorized',
            'isAuthorized',
            'portalAuthorized',
        ]), [true, 1, '1', 'true', 'authorized'], true)
            || in_array($rawPortalStatus, ['authorized', 'authenticated', 'success'], true)
            || ($rawPortalStatus === null && in_array($rawStatus, ['authorized', 'authenticated'], true));

        $connected = in_array($this->firstFilled($client, [
            'active',
            'isActive',
            'connected',
            'isOnline',
        ]), [true, 1, '1', 'true'], true)
            || in_array($rawStatus, ['connected', 'online', 'normal', 'up'], true);

        return [
            'controller_client_id' => $this->stringOrNull($this->firstFilled($client, ['id', 'clientId'])),
            'mac_address' => $macAddress,
            'site_identifier' => $this->stringOrNull($this->firstFilled($client, ['siteId', 'site_id', 'site.id'])),
            'site_name' => $this->stringOrNull($this->firstFilled($client, ['siteName', 'site_name', 'site', 'site.name'])),
            'ssid_name' => $this->stringOrNull($this->firstFilled($client, ['ssidName', 'ssid_name', 'ssid', 'wlan'])),
            'client_ip' => filter_var($this->firstFilled($client, ['ip', 'ipAddress', 'clientIp']), FILTER_VALIDATE_IP) ?: null,
            'authorized' => $authorized,
            'connected' => $connected,
            'raw_status' => $rawStatus !== '' ? $rawStatus : null,
            'raw_portal_status' => $rawPortalStatus,
        ];
    }

    private function normalizeClientStatus(mixed $statusValue): ?string
    {
        if ($statusValue === null || $statusValue === '') {
            return null;
        }

        if (is_bool($statusValue)) {
            return $statusValue ? 'connected' : 'disconnected';
        }

        if (is_numeric($statusValue)) {
            return match ((int) $statusValue) {
                1 => 'connected',
                0 => 'disconnected',
                2 => 'pending',
                default => (string) $statusValue,
            };
        }

        return strtolower((string) $statusValue);
    }

    private function normalizeClientPortalStatus(mixed $statusValue): ?string
    {
        if ($statusValue === null || $statusValue === '') {
            return null;
        }

        if (is_bool($statusValue)) {
            return $statusValue ? 'authorized' : 'unauthorized';
        }

        if (is_numeric($statusValue)) {
            return match ((int) $statusValue) {
                1 => 'authorized',
                2 => 'unauthorized',
                default => (string) $statusValue,
            };
        }

        return strtolower((string) $statusValue);
    }

    private function resolveSiteNameByIdentifier(string $siteId): ?string
    {
        return Site::query()
            ->where('omada_site_id', $siteId)
            ->value('name');
    }

    private function macLookupFingerprint(string $macAddress): string
    {
        return strtolower(str_replace(':', '', $macAddress));
    }

    private function normalizeMacForPath(?string $macAddress): string
    {
        $normalized = MacAddress::normalizeForDisplay($macAddress);

        if ($normalized === null) {
            throw new RuntimeException('Omada deauthorization requires a valid client MAC address.');
        }

        return str_replace(':', '-', $normalized);
    }
}
