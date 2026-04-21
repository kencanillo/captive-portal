<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\WifiSession;
use App\Support\PortalTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PortalDeviceContextService
{
    public function __construct(
        private readonly OmadaService $omadaService,
        private readonly PortalTokenService $portalTokenService,
    ) {
    }

    public function buildInitialContext(Request $request): array
    {
        return [
            'mac_address' => null,
            'ap_mac' => $this->firstFilled($request, ['apMac', 'ap_mac']),
            'ap_name' => $this->firstFilled($request, ['apName', 'ap_name']),
            'site_name' => $this->firstFilled($request, ['siteName', 'site_name', 'site']),
            'ssid_name' => $this->firstFilled($request, ['ssidName', 'ssid_name', 'ssid']),
            'radio_id' => $this->firstFilled($request, ['radioId', 'radio_id']),
            'client_ip' => $this->resolvedClientIp($request),
            'redirect_url' => $this->firstFilled($request, ['redirectUrl', 'redirect_url']),
        ];
    }

    public function resolve(Request $request): array
    {
        $startedAt = microtime(true);
        $requestId = $this->requestId($request);
        $portalContext = $this->buildInitialContext($request);
        $resolvedClientIp = Arr::get($portalContext, 'client_ip');
        $queryMacAddress = $this->normalizeMac(
            $this->firstFilled($request, ['clientMac', 'client_mac', 'mac_address', 'mac'])
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
            } elseif (config('portal.allow_query_mac_fallback', false)) {
                $macAddress = $queryMacAddress;
                $resolutionSource = 'query_mac';
                $status = 'resolved';
            }
        }

        $phase1DurationMs = $this->elapsedMilliseconds($phase1Start);

        $phase2Start = microtime(true);
        if (! $macAddress) {
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

            $deviceContextCacheHit = false;
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

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
