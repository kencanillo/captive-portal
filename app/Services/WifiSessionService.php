<?php

namespace App\Services;

use App\Exceptions\TransferRequiredException;
use App\Models\Client;
use App\Models\ClientDevice;
use App\Models\ControllerSetting;
use App\Models\Plan;
use App\Models\WifiSession;
use App\Support\MacAddress;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class WifiSessionService
{
    public function __construct(
        private readonly PortalSessionContextResolver $portalSessionContextResolver,
        private readonly OmadaService $omadaService,
        private readonly WifiSessionAuthorizationService $wifiSessionAuthorizationService,
    ) {}

    public function createSession(string $macAddress, Plan $plan, array $context = [], ?array $clientRegistrationData = null): WifiSession
    {
        return DB::transaction(function () use ($macAddress, $plan, $context, $clientRegistrationData) {
            $resolvedContext = $this->portalSessionContextResolver->resolve($context);
            $normalizedMacAddress = $this->normalizeMacAddress($macAddress);
            $client = $this->findOrCreateClient($normalizedMacAddress, $clientRegistrationData);
            $clientDevice = $this->resolveClientDevice($client, $normalizedMacAddress);
            $activeSession = $this->lockActiveSessionForClient($client->id);

            if ($activeSession && ! $this->macAddressesMatch($activeSession->mac_address, $normalizedMacAddress)) {
                throw TransferRequiredException::forClient(
                    $client->loadMissing('devices'),
                    $activeSession->loadMissing('plan'),
                    $normalizedMacAddress
                );
            }

            $existingPendingExtension = $activeSession
                ? $this->findPendingExtensionForActiveSession($activeSession->id, $clientDevice->id)
                : null;

            if ($existingPendingExtension) {
                if ((int) $existingPendingExtension->plan_id !== (int) $plan->id) {
                    throw new \RuntimeException('A renewal is already pending for this device. Continue the current checkout before starting another plan.');
                }

                return $existingPendingExtension;
            }

            return $this->createPendingSession(
                $client,
                $clientDevice,
                $normalizedMacAddress,
                $plan,
                $resolvedContext,
                $activeSession
            );
        });
    }

    public function activateSession(WifiSession $session): WifiSession
    {
        return DB::transaction(function () use ($session) {
            /** @var WifiSession $lockedSession */
            $lockedSession = WifiSession::query()
                ->with(['site', 'accessPoint', 'plan', 'client'])
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($lockedSession->payment_status !== WifiSession::PAYMENT_STATUS_PAID) {
                throw new \RuntimeException('Session cannot be activated without paid status.');
            }

            if ($lockedSession->session_status === WifiSession::SESSION_STATUS_ACTIVE && $lockedSession->is_active) {
                return $lockedSession;
            }

            $conflictingActiveSession = $this->lockActiveSessionForClient($lockedSession->client_id, $lockedSession->id);

            if ($conflictingActiveSession) {
                if ($this->macAddressesMatch($conflictingActiveSession->mac_address, $lockedSession->mac_address)) {
                    return $this->mergePaidRenewalIntoActiveSession($lockedSession, $conflictingActiveSession);
                }

                throw new \RuntimeException('Another device already has active internet access for this account.');
            }

            $start = Carbon::now();
            $end = $start->copy()->addMinutes($lockedSession->plan->duration_minutes);

            $lockedSession->forceFill([
                'start_time' => $start,
                'end_time' => $end,
                'session_status' => WifiSession::SESSION_STATUS_PAID,
                'release_status' => WifiSession::RELEASE_STATUS_IN_PROGRESS,
            ]);

            $settings = ControllerSetting::query()->first();

            if (! $settings) {
                throw new \RuntimeException('Omada controller settings are missing, so the paid client cannot be authorized.');
            }

            $this->omadaService->authorizeClient($settings, $lockedSession);

            $lockedSession->forceFill([
                'is_active' => true,
                'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
                'release_status' => WifiSession::RELEASE_STATUS_SUCCEEDED,
                'release_outcome_type' => WifiSession::RELEASE_OUTCOME_SUCCESS,
                'released_at' => $lockedSession->released_at ?? now(),
                'released_by_path' => 'legacy_activate_session',
                'last_release_error' => null,
                'release_failure_reason' => null,
                'controller_state_uncertain' => false,
                'release_stuck_at' => null,
            ])->save();

            return $this->wifiSessionAuthorizationService
                ->markSessionAuthorized($lockedSession, 'legacy_activate_session')
                ->refresh();
        });
    }

    public function markReleaseFailed(WifiSession $session, string $reason): WifiSession
    {
        $session->forceFill([
            'is_active' => false,
            'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
            'release_status' => WifiSession::RELEASE_STATUS_FAILED,
            'release_outcome_type' => WifiSession::RELEASE_OUTCOME_NON_RETRYABLE_VALIDATION_FAILURE,
            'last_release_error' => $reason,
            'release_failure_reason' => $reason,
            'controller_state_uncertain' => false,
        ])->save();

        Log::error('WiFi session release failed.', [
            'wifi_session_id' => $session->id,
            'reason' => $reason,
        ]);

        return $session->refresh();
    }

    public function expireSession(WifiSession $session, ?ControllerSetting $settings = null): WifiSession
    {
        $settings ??= ControllerSetting::query()->first();
        $session->loadMissing(['site', 'accessPoint', 'plan', 'client']);
        $deauthorizedInController = false;
        $deauthorizationError = null;

        if ($settings) {
            try {
                $this->omadaService->deauthorizeClient($settings, $session);
                $deauthorizedInController = true;
            } catch (\Throwable $exception) {
                $deauthorizationError = $exception->getMessage();

                Log::warning('Failed to deauthorize expired WiFi session in Omada.', [
                    'wifi_session_id' => $session->id,
                    'client_id' => $session->client_id,
                    'mac_address' => $session->mac_address,
                    'error' => $deauthorizationError,
                ]);
            }
        }

        $session->forceFill([
            'is_active' => false,
            'session_status' => WifiSession::SESSION_STATUS_EXPIRED,
        ])->save();

        if ($deauthorizedInController) {
            $expiredSession = $this->wifiSessionAuthorizationService->markSessionDeauthorized($session, 'session_expired');
            $expiredSession->forceFill([
                'controller_deauthorization_status' => WifiSession::CONTROLLER_DEAUTH_STATUS_SUCCEEDED,
                'controller_deauthorization_attempt_count' => (int) $expiredSession->controller_deauthorization_attempt_count + 1,
                'controller_deauthorization_last_attempt_at' => now(),
                'controller_deauthorization_next_attempt_at' => null,
                'controller_deauthorization_last_error' => null,
            ])->save();
            $expiredSession = $expiredSession->refresh();
        } else {
            $expiredSession = $this->markExpiredSessionPendingControllerDeauthorization(
                $session,
                $deauthorizationError ?? 'Omada controller settings were unavailable.'
            );
        }

        Log::info('WiFi session expired.', [
            'wifi_session_id' => $expiredSession->id,
            'client_id' => $expiredSession->client_id,
            'mac_address' => $expiredSession->mac_address,
            'source' => $expiredSession->source,
            'controller_deauth_attempted' => $settings !== null,
            'controller_deauth_succeeded' => $deauthorizedInController,
        ]);

        if (in_array($expiredSession->source, ['manual_operator', 'manual_admin'], true)) {
            Log::info('Expired manual session deauthorized.', [
                'wifi_session_id' => $expiredSession->id,
                'source' => $expiredSession->source,
            ]);
        }

        return $expiredSession;
    }

    public function retryPendingDeauthorizations(int $limit = 100): array
    {
        $settings = ControllerSetting::query()->first();

        if (! $settings) {
            Log::warning('Skipping pending WiFi session deauthorization retries because controller settings are missing.');

            return [
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'manual_required' => 0,
                'skipped' => 0,
            ];
        }

        $summary = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'manual_required' => 0,
            'skipped' => 0,
        ];
        $maxAttempts = $this->controllerDeauthorizationMaxAttempts();

        WifiSession::query()
            ->with(['site', 'accessPoint', 'plan', 'client'])
            ->where('payment_status', WifiSession::PAYMENT_STATUS_PAID)
            ->where('is_active', false)
            ->where('session_status', WifiSession::SESSION_STATUS_EXPIRED)
            ->whereNull('deauthorized_at')
            ->whereIn('controller_deauthorization_status', [
                WifiSession::CONTROLLER_DEAUTH_STATUS_PENDING,
                WifiSession::CONTROLLER_DEAUTH_STATUS_FAILED,
            ])
            ->where('controller_deauthorization_attempt_count', '<', $maxAttempts)
            ->where(function ($query): void {
                $query->whereNull('controller_deauthorization_next_attempt_at')
                    ->orWhere('controller_deauthorization_next_attempt_at', '<=', now());
            })
            ->orderByRaw('controller_deauthorization_next_attempt_at IS NULL DESC')
            ->orderBy('controller_deauthorization_next_attempt_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (WifiSession $session) use ($settings, &$summary): void {
                $summary['processed']++;

                $result = $this->retryPendingDeauthorization($session, $settings);

                if ($result === WifiSession::CONTROLLER_DEAUTH_STATUS_SUCCEEDED) {
                    $summary['succeeded']++;

                    return;
                }

                if ($result === WifiSession::CONTROLLER_DEAUTH_STATUS_MANUAL_REQUIRED) {
                    $summary['manual_required']++;

                    return;
                }

                $summary['failed']++;
            });

        Log::info('Pending WiFi session deauthorization retry completed.', $summary);

        return $summary;
    }

    private function retryPendingDeauthorization(WifiSession $session, ControllerSetting $settings): string
    {
        try {
            $this->omadaService->deauthorizeClient($settings, $session);

            $deauthorizedSession = $this->wifiSessionAuthorizationService->markSessionDeauthorized(
                $session,
                'session_expired_retry'
            );

            $deauthorizedSession->forceFill([
                'controller_deauthorization_status' => WifiSession::CONTROLLER_DEAUTH_STATUS_SUCCEEDED,
                'controller_deauthorization_attempt_count' => (int) $deauthorizedSession->controller_deauthorization_attempt_count + 1,
                'controller_deauthorization_last_attempt_at' => now(),
                'controller_deauthorization_next_attempt_at' => null,
                'controller_deauthorization_last_error' => null,
            ])->save();

            Log::info('Pending WiFi session deauthorization retry succeeded.', [
                'wifi_session_id' => $deauthorizedSession->id,
                'client_id' => $deauthorizedSession->client_id,
                'mac_address' => $deauthorizedSession->mac_address,
                'controller_deauthorization_attempt_count' => $deauthorizedSession->controller_deauthorization_attempt_count,
            ]);

            return WifiSession::CONTROLLER_DEAUTH_STATUS_SUCCEEDED;
        } catch (\Throwable $exception) {
            $pendingSession = $this->markExpiredSessionPendingControllerDeauthorization($session, $exception->getMessage());

            Log::warning('Pending WiFi session deauthorization retry failed.', [
                'wifi_session_id' => $pendingSession->id,
                'client_id' => $pendingSession->client_id,
                'mac_address' => $pendingSession->mac_address,
                'controller_deauthorization_status' => $pendingSession->controller_deauthorization_status,
                'controller_deauthorization_attempt_count' => $pendingSession->controller_deauthorization_attempt_count,
                'error' => $exception->getMessage(),
            ]);

            return $pendingSession->controller_deauthorization_status;
        }
    }

    private function markExpiredSessionPendingControllerDeauthorization(WifiSession $session, string $reason): WifiSession
    {
        $pendingSession = $this->wifiSessionAuthorizationService->markSessionDeauthorizationPending(
            $session,
            'session_expired_local_only'
        );

        $attemptCount = (int) $pendingSession->controller_deauthorization_attempt_count + 1;
        $nextRetryAt = now()->addSeconds($this->controllerDeauthorizationBackoffSeconds($attemptCount));
        $status = $attemptCount >= $this->controllerDeauthorizationMaxAttempts()
            ? WifiSession::CONTROLLER_DEAUTH_STATUS_MANUAL_REQUIRED
            : WifiSession::CONTROLLER_DEAUTH_STATUS_FAILED;

        $pendingSession->forceFill([
            'controller_deauthorization_status' => $status,
            'controller_deauthorization_attempt_count' => $attemptCount,
            'controller_deauthorization_last_attempt_at' => now(),
            'controller_deauthorization_next_attempt_at' => $status === WifiSession::CONTROLLER_DEAUTH_STATUS_MANUAL_REQUIRED
                ? null
                : $nextRetryAt,
            'controller_deauthorization_last_error' => $reason,
        ])->save();

        Log::warning('Expired WiFi session still needs controller deauthorization retry.', [
            'wifi_session_id' => $pendingSession->id,
            'client_id' => $pendingSession->client_id,
            'mac_address' => $pendingSession->mac_address,
            'controller_deauthorization_status' => $status,
            'controller_deauthorization_attempt_count' => $attemptCount,
            'controller_deauthorization_next_attempt_at' => $pendingSession->controller_deauthorization_next_attempt_at?->toDateTimeString(),
            'error' => $reason,
        ]);

        return $pendingSession->refresh();
    }

    private function controllerDeauthorizationMaxAttempts(): int
    {
        return max(1, (int) config('portal.omada_deauthorization_max_attempts', 20));
    }

    private function controllerDeauthorizationBackoffSeconds(int $attemptCount): int
    {
        $retrySeconds = 60 * (2 ** max(0, $attemptCount - 1));

        return min(900, $retrySeconds);
    }

    public function checkIfExpired(WifiSession $session): bool
    {
        if (! $session->is_active || ! $session->end_time) {
            return false;
        }

        if ($session->end_time->isPast()) {
            $this->expireSession($session);

            return true;
        }

        return false;
    }

    public function expireAllDueSessions(): int
    {
        $settings = ControllerSetting::query()->first();
        $expiredCount = 0;

        WifiSession::query()
            ->where('is_active', true)
            ->whereNotNull('end_time')
            ->where('end_time', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use (&$expiredCount, $settings): void {
                foreach ($sessions as $session) {
                    Log::info('Expiring due WiFi session.', [
                        'wifi_session_id' => $session->id,
                        'client_id' => $session->client_id,
                        'mac_address' => $session->mac_address,
                    ]);
                    $this->expireSession($session, $settings);
                    $expiredCount++;
                }
            });

        return $expiredCount;
    }

    public function mergePaidRenewalIntoActiveSession(WifiSession $renewalSession, WifiSession $activeSession): WifiSession
    {
        $renewalSession->loadMissing('plan');
        $activeSession->loadMissing('plan');

        if ($renewalSession->session_status === WifiSession::SESSION_STATUS_MERGED
            && (int) $renewalSession->merged_into_session_id === (int) $activeSession->id) {
            return $renewalSession->refresh();
        }

        $baseEnd = $activeSession->end_time && $activeSession->end_time->isFuture()
            ? $activeSession->end_time->copy()
            : Carbon::now();

        $newEnd = $baseEnd->addMinutes($renewalSession->plan->duration_minutes);

        $activeSession->forceFill([
            'end_time' => $newEnd,
            'release_failure_reason' => null,
            'last_release_error' => null,
            'controller_state_uncertain' => false,
            'release_outcome_type' => WifiSession::RELEASE_OUTCOME_SUCCESS,
            'release_stuck_at' => null,
        ])->save();
        $this->wifiSessionAuthorizationService->markSessionAuthorized($activeSession, 'renewal_merge');

        $renewalSession->forceFill([
            'start_time' => $activeSession->start_time ?? Carbon::now(),
            'end_time' => $newEnd,
            'is_active' => false,
            'session_status' => WifiSession::SESSION_STATUS_MERGED,
            'release_status' => WifiSession::RELEASE_STATUS_SUCCEEDED,
            'release_outcome_type' => WifiSession::RELEASE_OUTCOME_SUCCESS,
            'released_at' => now(),
            'released_by_path' => 'renewal_merge',
            'last_release_error' => null,
            'merged_into_session_id' => $activeSession->id,
            'release_failure_reason' => null,
            'controller_state_uncertain' => false,
            'release_stuck_at' => null,
        ])->save();

        return $renewalSession->refresh();
    }

    private function createPendingSession(
        Client $client,
        ClientDevice $clientDevice,
        string $macAddress,
        Plan $plan,
        array $resolvedContext,
        ?WifiSession $extendsSession = null
    ): WifiSession {
        return WifiSession::query()->create([
            'client_id' => $client->id,
            'client_device_id' => $clientDevice->id,
            'mac_address' => $macAddress,
            'plan_id' => $plan->id,
            'site_id' => $resolvedContext['site_id'],
            'access_point_id' => $resolvedContext['access_point_id'],
            'ap_mac' => $resolvedContext['ap_mac'],
            'ap_name' => $resolvedContext['ap_name'],
            'ssid_name' => $resolvedContext['ssid_name'],
            'radio_id' => $resolvedContext['radio_id'] ?? null,
            'client_ip' => $resolvedContext['client_ip'],
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PENDING,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
            'extends_session_id' => $extendsSession?->id,
        ]);
    }

    private function findOrCreateClient(string $macAddress, ?array $registrationData): Client
    {
        if (! $registrationData) {
            throw new \RuntimeException('PIN verification is required before payment can continue.');
        }

        $existingByMac = $this->findClientByBoundMacAddress($macAddress);

        if ($existingByMac) {
            if (! $this->phoneNumbersMatch($registrationData['phone_number'], $existingByMac->phone_number)
                || ! Hash::check($registrationData['pin'], $existingByMac->pin)) {
                throw new \RuntimeException('The phone number or PIN does not match the existing client record for this device.');
            }

            $existingByMac->forceFill([
                'name' => $registrationData['name'] ?: $existingByMac->name,
                'mac_address' => $macAddress,
                'last_connected_at' => now(),
            ])->save();

            return $existingByMac->refresh();
        }

        $existingByPhone = Client::query()
            ->where('phone_number', $registrationData['phone_number'])
            ->lockForUpdate()
            ->first();

        if ($existingByPhone) {
            if (! Hash::check($registrationData['pin'], $existingByPhone->pin)) {
                throw new \RuntimeException('The PIN does not match the existing client record for this phone number.');
            }

            if (! $this->clientHasBoundMacAddress($existingByPhone, $macAddress)) {
                throw TransferRequiredException::forClient(
                    $existingByPhone->loadMissing('devices'),
                    $this->lockActiveSessionForClient($existingByPhone->id)?->loadMissing('plan'),
                    $macAddress
                );
            }

            $existingByPhone->forceFill([
                'name' => $registrationData['name'] ?: $existingByPhone->name,
                'mac_address' => $macAddress,
                'last_connected_at' => now(),
            ])->save();

            return $existingByPhone->refresh();
        }

        return Client::query()->create([
            'name' => $registrationData['name'],
            'phone_number' => $registrationData['phone_number'],
            'pin' => bcrypt($registrationData['pin']),
            'mac_address' => $macAddress,
            'last_connected_at' => now(),
        ]);
    }

    private function resolveClientDevice(Client $client, string $macAddress): ClientDevice
    {
        $device = ClientDevice::query()->firstOrCreate(
            ['mac_address' => $macAddress],
            [
                'client_id' => $client->id,
                'status' => 'bound',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]
        );

        if ((int) $device->client_id !== (int) $client->id) {
            throw new \RuntimeException('The detected device is already bound to another account.');
        }

        $device->forceFill([
            'status' => 'bound',
            'first_seen_at' => $device->first_seen_at ?? now(),
            'last_seen_at' => now(),
        ])->save();

        if (strtolower((string) $client->mac_address) !== $macAddress) {
            // Legacy compatibility only. Canonical device binding lives in client_devices.
            $client->forceFill(['mac_address' => $macAddress])->save();
        }

        return $device->refresh();
    }

    private function lockActiveSessionForClient(int $clientId, ?int $exceptSessionId = null): ?WifiSession
    {
        return WifiSession::query()
            ->with(['plan', 'client'])
            ->where('client_id', $clientId)
            // DB-level guards only enforce one row with is_active=1. Time expiry is still application-driven.
            ->where('is_active', true)
            ->whereNotNull('end_time')
            ->where('end_time', '>', now())
            ->when($exceptSessionId, fn ($query) => $query->whereKeyNot($exceptSessionId))
            ->lockForUpdate()
            ->orderByDesc('end_time')
            ->first();
    }

    private function findPendingExtensionForActiveSession(int $activeSessionId, int $clientDeviceId): ?WifiSession
    {
        return WifiSession::query()
            ->where('extends_session_id', $activeSessionId)
            ->where('client_device_id', $clientDeviceId)
            ->whereNull('merged_into_session_id')
            ->where('session_status', WifiSession::SESSION_STATUS_PENDING_PAYMENT)
            ->whereIn('payment_status', [
                WifiSession::PAYMENT_STATUS_PENDING,
                WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            ])
            ->lockForUpdate()
            ->latest('id')
            ->first();
    }

    private function clientHasBoundMacAddress(Client $client, string $macAddress): bool
    {
        $deviceExists = ClientDevice::query()
            ->where('client_id', $client->id)
            ->whereRaw('LOWER(mac_address) = ?', [$macAddress])
            ->lockForUpdate()
            ->exists();

        if ($deviceExists) {
            return true;
        }

        return ! $client->devices()->exists() && $this->macAddressesMatch($client->mac_address, $macAddress);
    }

    private function findClientByBoundMacAddress(string $macAddress): ?Client
    {
        $boundDevice = ClientDevice::query()
            ->whereRaw('LOWER(mac_address) = ?', [$macAddress])
            ->lockForUpdate()
            ->first();

        if ($boundDevice) {
            $client = Client::query()->lockForUpdate()->find($boundDevice->client_id);

            if ($client && strtolower((string) $client->mac_address) !== $macAddress) {
                // Legacy compatibility only. Device ownership lives in client_devices.
                $client->forceFill(['mac_address' => $macAddress])->save();
            }

            return $client;
        }

        $legacyClient = Client::query()
            ->whereRaw('LOWER(mac_address) = ?', [$macAddress])
            ->lockForUpdate()
            ->first();

        if (! $legacyClient || $legacyClient->devices()->exists()) {
            return null;
        }

        return $legacyClient;
    }

    private function normalizeMacAddress(string $macAddress): string
    {
        $normalized = MacAddress::normalizeForStorage($macAddress);

        if ($normalized === null) {
            throw new \RuntimeException('A valid client MAC address is required.');
        }

        return $normalized;
    }

    private function macAddressesMatch(?string $left, ?string $right): bool
    {
        return MacAddress::equals($left, $right);
    }

    private function phoneNumbersMatch(string $left, string $right): bool
    {
        return trim($left) === trim($right);
    }
}
