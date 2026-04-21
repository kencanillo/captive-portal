<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\WifiSession;
use App\Support\PortalTokenService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PortalDeviceContextService
{
    public function __construct(
        private readonly OmadaService $omadaService,
        private readonly PortalTokenService $portalTokenService,
    ) {
    }

    public function buildInitialContext(Request $request): array
    {
        $siteContext = $this->resolveIncomingSiteContext($request);

        return [
            'mac_address' => null,
            'ap_mac' => $this->firstFilled($request, ['apMac', 'ap_mac', 'ap']),
            'ap_name' => $this->firstFilled($request, ['apName', 'ap_name']),
            'site_name' => $siteContext['site_name'],
            'site_identifier' => $siteContext['site_identifier'],
            'ssid_name' => $this->firstFilled($request, ['ssidName', 'ssid_name', 'ssid']),
            'radio_id' => $this->firstFilled($request, ['radioId', 'radio_id']),
            'client_ip' => $this->resolvedClientIp($request),
            'redirect_url' => $this->firstFilled($request, ['redirectUrl', 'redirect_url']),
        ];
    }

    public function resolve(Request $request): array
    {
        return $this->resolveInternal($request);
    }

    public function resolveForInitialPage(Request $request, ?string $requestId = null): array
    {
        return $this->resolveInternal($request, $requestId, false);
    }

    private function resolveInternal(
        Request $request,
        ?string $requestIdOverride = null,
        bool $allowOmadaLookup = true,
    ): array
    {
        $startedAt = microtime(true);
        $requestId = $requestIdOverride !== null ? trim($requestIdOverride) : $this->requestId($request);
        $portalContext = $this->buildInitialContext($request);
        $resolvedClientIp = Arr::get($portalContext, 'client_ip');
        $queryMacAddress = $this->normalizeMac(
            $this->firstFilled($request, ['clientMac', 'client_mac', 'cid', 'mac_address', 'mac'])
        );
        $macAddress = null;
        $existingClient = null;
        $activeSession = null;
        $resolutionSource = 'none';
        $status = 'pending';
        $errorCode = null;
        $retryAfterMs = $this->defaultRetryAfterMs();
        $deviceContextCacheHit = false;

        $phase1Start = microtime(true);
        if ($queryMacAddress) {
            $existingClient = Client::findByMacAddress($queryMacAddress);
            $activeSession = $this->findActiveSession($queryMacAddress);

            if ($existingClient) {
                $macAddress = $existingClient->mac_address;
                $resolutionSource = 'known_client_db';
                $status = 'resolved';
            } elseif ($activeSession) {
                $macAddress = strtoupper($activeSession->mac_address);
                $resolutionSource = 'active_session_query_mac';
                $status = 'resolved';
            } else {
                $macAddress = $queryMacAddress;
                $resolutionSource = 'redirect_query_mac';
                $status = 'resolved';
            }
        }

        if (! $macAddress && $resolvedClientIp) {
            $cachedContext = $this->safeCacheGet($this->deviceContextCacheKey($portalContext));

            if (is_array($cachedContext) && filled($cachedContext['mac_address'] ?? null)) {
                $macAddress = $this->normalizeMac($cachedContext['mac_address']);
                $resolutionSource = 'device_context_cache';
                $status = 'resolved';
                $deviceContextCacheHit = true;
            }
        }

        if (! $macAddress && $resolvedClientIp) {
            $sessionByIp = $this->findSessionByClientIp($resolvedClientIp);

            if ($sessionByIp && filled($sessionByIp->mac_address)) {
                $macAddress = strtoupper($sessionByIp->mac_address);
                $existingClient = $sessionByIp->client;

                if ($this->sessionIsActive($sessionByIp)) {
                    $activeSession = $sessionByIp;
                    $resolutionSource = 'active_session_ip';
                } else {
                    $resolutionSource = 'recent_session_ip';
                }

                $status = 'resolved';
            }
        }
        $phase1DurationMs = $this->elapsedMilliseconds($phase1Start);

        $phase2Start = microtime(true);
        if (! $macAddress && $allowOmadaLookup) {
            $controllerSettings = ControllerSetting::singleton();

            if ($controllerSettings->canTestConnection()) {
                $omadaLookup = $this->omadaService->lookupPortalClientContext(
                    $controllerSettings,
                    $resolvedClientIp,
                    $requestId
                );

                $macAddress = $omadaLookup['mac_address'];
                $resolutionSource = $omadaLookup['resolution_source'];
                $status = $omadaLookup['status'];
                $errorCode = $omadaLookup['error_code'];
                $retryAfterMs = $omadaLookup['retry_after_ms'];
            } else {
                $status = 'failed';
                $resolutionSource = 'controller_unavailable';
                $errorCode = 'controller_unavailable';
            }
        }
        $phase2DurationMs = $this->elapsedMilliseconds($phase2Start);

        $phase3Start = microtime(true);
        if ($macAddress) {
            $portalContext['mac_address'] = $macAddress;
            $existingClient ??= Client::findByMacAddress($macAddress);
            $activeSession ??= $this->findActiveSession($macAddress);
            $status = 'resolved';

            $this->safeCachePut(
                $this->deviceContextCacheKey($portalContext),
                ['mac_address' => $macAddress],
                (int) config('portal.device_context_positive_cache_seconds', 300)
            );
        }
        $phase3DurationMs = $this->elapsedMilliseconds($phase3Start);

        $response = [
            'status' => $status,
            'request_id' => $requestId,
            'resolution_source' => $resolutionSource,
            'retry_after_ms' => $status === 'resolved' ? 0 : $retryAfterMs,
            'error_code' => $errorCode,
            'portal_context' => $portalContext,
            'portal_token' => $macAddress
                ? $this->portalTokenService->issuePortalContextToken($portalContext)
                : null,
            'existing_client' => $existingClient ? [
                'id' => $existingClient->id,
                'name' => $existingClient->name,
                'phone_number' => $existingClient->phone_number,
                'mac_address' => $existingClient->mac_address,
            ] : null,
            'active_session' => $activeSession ? [
                'id' => $activeSession->id,
                'session_status' => $activeSession->session_status,
                'payment_status' => $activeSession->payment_status,
                'start_time' => $activeSession->start_time?->toIso8601String(),
                'end_time' => $activeSession->end_time?->toIso8601String(),
                'client_name' => $activeSession->client?->name,
                'phone_number' => $activeSession->client?->phone_number,
                'plan' => $activeSession->plan ? [
                    'id' => $activeSession->plan->id,
                    'name' => $activeSession->plan->name,
                    'duration_minutes' => $activeSession->plan->duration_minutes,
                ] : null,
            ] : null,
        ];

        Log::info('Portal device context resolved.', [
            'request_id' => $requestId,
            'client_ip' => $resolvedClientIp,
            'query_mac_present' => filled($queryMacAddress),
            'resolution_source' => $resolutionSource,
            'status' => $status,
            'error_code' => $errorCode,
            'cache_hit' => $deviceContextCacheHit,
            'has_mac_address' => filled($macAddress),
            'has_existing_client' => $existingClient !== null,
            'has_active_session' => $activeSession !== null,
            'phase1_db_lookup_ms' => $phase1DurationMs,
            'phase2_omada_lookup_ms' => $phase2DurationMs,
            'phase3_session_lookup_ms' => $phase3DurationMs,
            'total_duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);

        return $response;
    }

    private function findActiveSession(string $macAddress): ?WifiSession
    {
        return WifiSession::query()
            ->with(['client:id,name,phone_number', 'plan:id,name,duration_minutes'])
            ->whereRaw('LOWER(mac_address) = ?', [strtolower($macAddress)])
            ->where('is_active', true)
            ->whereNotNull('end_time')
            ->where('end_time', '>', now())
            ->latest('end_time')
            ->first();
    }

    private function findSessionByClientIp(string $clientIp): ?WifiSession
    {
        return WifiSession::query()
            ->with(['client:id,name,phone_number,mac_address', 'plan:id,name,duration_minutes'])
            ->where('client_ip', $clientIp)
            ->where(function ($query): void {
                $query->where(function ($activeQuery): void {
                    $activeQuery->where('is_active', true)
                        ->whereNotNull('end_time')
                        ->where('end_time', '>', now());
                })->orWhere('created_at', '>=', now()->subMinutes(30));
            })
            ->orderByDesc('is_active')
            ->latest('created_at')
            ->first();
    }

    private function sessionIsActive(WifiSession $session): bool
    {
        return (bool) $session->is_active
            && $session->end_time !== null
            && $session->end_time->isFuture();
    }

    private function requestId(Request $request): string
    {
        $value = trim((string) ($request->header('X-Portal-Request-Id') ?: $request->query('request_id', '')));

        return $value !== '' ? Str::limit($value, 100, '') : (string) Str::uuid();
    }

    private function resolvedClientIp(Request $request): ?string
    {
        $candidate = $this->firstFilled($request, ['clientIp', 'client_ip']) ?: $request->ip();

        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : null;
    }

    private function firstFilled(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $request->query($key);

            if ($value === null) {
                foreach ($request->query() as $queryKey => $queryValue) {
                    if (strtolower((string) $queryKey) === strtolower($key)) {
                        $value = $queryValue;
                        break;
                    }
                }
            }

            $value = trim((string) ($value ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveIncomingSiteContext(Request $request): array
    {
        $siteName = $this->firstFilled($request, ['siteName', 'site_name']);
        $siteIdentifier = $this->firstFilled($request, ['siteId', 'site_id']);
        $siteValue = $this->firstFilled($request, ['site']);

        if (! $siteIdentifier && $this->looksLikeOmadaSiteIdentifier($siteValue)) {
            $siteIdentifier = $siteValue;
        }

        if (! $siteName && $siteValue && ! $this->looksLikeOmadaSiteIdentifier($siteValue)) {
            $siteName = $siteValue;
        }

        return [
            'site_name' => $siteName,
            'site_identifier' => $siteIdentifier,
        ];
    }

    private function looksLikeOmadaSiteIdentifier(?string $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{24}$/i', trim($value)) === 1;
    }

    private function normalizeMac(?string $value): ?string
    {
        $mac = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string) $value) ?? '');

        if (strlen($mac) !== 12) {
            return null;
        }

        return implode(':', str_split($mac, 2));
    }

    private function defaultRetryAfterMs(): int
    {
        return max(250, (int) config('portal.device_context_retry_after_ms', 1500));
    }

    private function deviceContextCacheKey(array $portalContext): string
    {
        return 'portal:device_context:' . sha1(json_encode([
            Arr::get($portalContext, 'client_ip'),
            Arr::get($portalContext, 'site_name'),
            Arr::get($portalContext, 'ap_mac'),
            Arr::get($portalContext, 'ssid_name'),
        ], JSON_THROW_ON_ERROR));
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
            Log::warning('Portal device context cache read failed.', [
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
            Log::warning('Portal device context cache write failed.', [
                'cache_key' => $key,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
