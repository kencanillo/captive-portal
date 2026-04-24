<?php

namespace App\Services;

use App\Models\Client;
use App\Support\MacAddress;
use App\Support\PortalTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PortalDeviceContextService
{
    public function __construct(
        private readonly WifiSessionAuthorizationService $wifiSessionAuthorizationService,
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
        $sourceIp = $request->ip();
        $trustedResolution = $this->resolveTrustedClientMacFromRequest($request);
        $macAddress = $trustedResolution['mac_address'];
        $existingClient = null;
        $activeSession = null;
        $resolutionSource = $trustedResolution['resolution_source'];
        $status = $trustedResolution['trusted'] ? 'resolved' : 'failed';
        $errorCode = $trustedResolution['error_code'];
        $retryAfterMs = 0;

        $lookupStart = microtime(true);
        if ($macAddress) {
            $portalContext['mac_address'] = $macAddress;
            $existingClient ??= Client::findByMacAddress($macAddress);
            $activeSession ??= $this->wifiSessionAuthorizationService->findActiveSessionForMac($macAddress, $portalContext);
            $status = 'resolved';
        }
        $lookupDurationMs = $this->elapsedMilliseconds($lookupStart);

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
            'source_ip' => $sourceIp,
            'client_ip' => $portalContext['client_ip'],
            'captive_context_present' => $trustedResolution['captive_context_present'],
            'resolution_trusted' => $trustedResolution['trusted'],
            'resolution_source' => $resolutionSource,
            'resolved_mac' => $macAddress,
            'rejected_reason' => $trustedResolution['rejected_reason'],
            'status' => $status,
            'error_code' => $errorCode,
            'has_mac_address' => filled($macAddress),
            'has_existing_client' => $existingClient !== null,
            'has_active_session' => $activeSession !== null,
            'active_session_lookup_ms' => $lookupDurationMs,
            'total_duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);

        return $response;
    }

    public function resolveTrustedClientMacFromRequest(Request $request): array
    {
        $providedQueryMac = $this->firstFilled($request, ['clientMac', 'client_mac']);
        $normalizedMac = MacAddress::normalizeForDisplay($providedQueryMac);

        if ($normalizedMac !== null) {
            return [
                'trusted' => true,
                'captive_context_present' => true,
                'mac_address' => $normalizedMac,
                'resolution_source' => 'trusted_query_client_mac',
                'error_code' => null,
                'rejected_reason' => null,
            ];
        }

        return [
            'trusted' => false,
            'captive_context_present' => false,
            'mac_address' => null,
            'resolution_source' => 'trusted_captive_context_missing',
            'error_code' => $providedQueryMac !== null ? 'invalid_client_mac' : 'missing_captive_context',
            'rejected_reason' => $providedQueryMac !== null ? 'invalid_client_mac' : 'missing_captive_context',
        ];
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

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
