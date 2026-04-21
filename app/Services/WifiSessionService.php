<?php

namespace App\Services;

use App\Exceptions\DeviceDecisionRequiredException;
use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\Plan;
use App\Models\WifiSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WifiSessionService
{
    public function __construct(
        private readonly PortalSessionContextResolver $portalSessionContextResolver,
        private readonly OmadaService $omadaService,
    ) {
    }

    public function createSession(
        string $macAddress,
        Plan $plan,
        array $context = [],
        ?array $clientRegistrationData = null,
        array $options = [],
    ): WifiSession
    {
        $resolvedContext = $this->portalSessionContextResolver->resolve($context);
        $resolution = $this->resolveClientForSession(
            $macAddress,
            $resolvedContext,
            $clientRegistrationData,
            $options
        );

        if (($resolution['session'] ?? null) instanceof WifiSession) {
            return $resolution['session'];
        }

        return $this->createPendingSession($resolution['client'], $macAddress, $plan, $resolvedContext);
    }

    private function resolveClientForSession(
        string $macAddress,
        array $resolvedContext,
        ?array $registrationData,
        array $options,
    ): array
    {
        $client = Client::findByMacAddress($macAddress);

        if ($client) {
            $client->update(['last_connected_at' => now()]);

            return ['client' => $client->refresh()];
        }

        if ($registrationData === null) {
            throw new RuntimeException('Client registration data is required for new clients.');
        }

        $existingByPhoneAndPin = Client::findByPhoneAndPin($registrationData['phone_number'], $registrationData['pin']);

        if (! $existingByPhoneAndPin) {
            return [
                'client' => $this->createClientRecord(
                    $macAddress,
                    $registrationData,
                    $registrationData['pin']
                ),
            ];
        }

        if (strtolower($existingByPhoneAndPin->mac_address) === strtolower($macAddress)) {
            $existingByPhoneAndPin->update([
                'name' => $registrationData['name'] ?: $existingByPhoneAndPin->name,
                'last_connected_at' => now(),
            ]);

            return ['client' => $existingByPhoneAndPin->refresh()];
        }

        $deviceDecision = $options['device_decision'] ?? null;
        $newPin = $options['new_pin'] ?? null;
        $requestId = $options['request_id'] ?? null;

        $activeOtherDeviceSession = $this->findActiveSessionForClient(
            $existingByPhoneAndPin,
            $macAddress
        );
        $omadaConnectedDevice = $this->lookupConnectedDeviceState($existingByPhoneAndPin->mac_address, $requestId);
        $hasConnectedTransferCandidate = $activeOtherDeviceSession !== null
            && (($omadaConnectedDevice['is_connected'] ?? null) !== false);
        $singleActiveDeviceOnly = (bool) config('portal.single_active_device_per_client', false);
        $transferLockedUntil = $this->transferLockedUntil($existingByPhoneAndPin);
        $canTransfer = $hasConnectedTransferCandidate && $transferLockedUntil === null;
        $canPay = ! $singleActiveDeviceOnly;
        $decisionPayload = $this->buildDeviceDecisionPayload(
            $existingByPhoneAndPin,
            $macAddress,
            $activeOtherDeviceSession,
            $omadaConnectedDevice,
            $canTransfer,
            $canPay,
            $singleActiveDeviceOnly,
            $transferLockedUntil
        );

        if (! in_array($deviceDecision, ['pay', 'transfer'], true)) {
            throw new DeviceDecisionRequiredException($decisionPayload);
        }

        if ($deviceDecision === 'transfer') {
            if (! $canTransfer || ! $activeOtherDeviceSession) {
                throw new DeviceDecisionRequiredException($decisionPayload);
            }

            return [
                'client' => $existingByPhoneAndPin,
                'session' => $this->transferActiveSessionToNewDevice(
                    $existingByPhoneAndPin,
                    $macAddress,
                    $resolvedContext,
                    $activeOtherDeviceSession
                ),
            ];
        }

        if (! $canPay) {
            throw new DeviceDecisionRequiredException($decisionPayload);
        }

        if (! is_string($newPin) || trim($newPin) === '') {
            throw new RuntimeException('A different PIN is required before this device can pay separately.');
        }

        if (Hash::check($newPin, $existingByPhoneAndPin->pin) || $newPin === $registrationData['pin']) {
            throw new RuntimeException('Use a different PIN for the second paid device.');
        }

        $existingWithNewPin = Client::findByPhoneAndPin($registrationData['phone_number'], $newPin);

        if ($existingWithNewPin) {
            throw new RuntimeException('That PIN is already in use for this phone number. Use a different PIN for the new device.');
        }

        return [
            'client' => $this->createClientRecord(
                $macAddress,
                $registrationData,
                $newPin
            ),
        ];
    }

    private function createClientRecord(string $macAddress, array $registrationData, string $pin): Client
    {
        return Client::query()->create([
            'name' => $registrationData['name'],
            'phone_number' => $registrationData['phone_number'],
            'pin' => bcrypt($pin),
            'mac_address' => $macAddress,
            'last_connected_at' => now(),
        ]);
    }

    private function createPendingSession(Client $client, string $macAddress, Plan $plan, array $resolvedContext): WifiSession
    {
        return DB::transaction(function () use ($client, $macAddress, $plan, $resolvedContext): WifiSession {
            return WifiSession::create([
                'client_id' => $client->id,
                'mac_address' => strtolower($macAddress),
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
            ]);
        });
    }

    private function buildDeviceDecisionPayload(
        Client $client,
        string $newMacAddress,
        ?WifiSession $activeOtherDeviceSession,
        ?array $omadaConnectedDevice,
        bool $canTransfer,
        bool $canPay,
        bool $singleActiveDeviceOnly,
        ?Carbon $transferLockedUntil,
    ): array {
        return [
            'code' => 'device_action_required',
            'message' => $singleActiveDeviceOnly
                ? 'This account already has an active session on another device.'
                : 'This phone number and PIN already belong to another device.',
            'single_active_device_only' => $singleActiveDeviceOnly,
            'can_transfer' => $canTransfer,
            'can_pay' => $canPay,
            'require_new_pin_for_pay' => true,
            'current_mac_address' => strtoupper($newMacAddress),
            'existing_client' => [
                'id' => $client->id,
                'name' => $client->name,
                'phone_number' => $client->phone_number,
                'mac_address' => $client->mac_address,
                'last_connected_at' => $client->last_connected_at?->toIso8601String(),
                'last_transferred_at' => $client->last_transferred_at?->toIso8601String(),
            ],
            'active_session' => $activeOtherDeviceSession ? [
                'id' => $activeOtherDeviceSession->id,
                'session_status' => $activeOtherDeviceSession->session_status,
                'payment_status' => $activeOtherDeviceSession->payment_status,
                'start_time' => $activeOtherDeviceSession->start_time?->toIso8601String(),
                'end_time' => $activeOtherDeviceSession->end_time?->toIso8601String(),
                'plan_name' => $activeOtherDeviceSession->plan?->name,
            ] : null,
            'connected_device' => $omadaConnectedDevice,
            'transfer_locked_until' => $transferLockedUntil?->toIso8601String(),
        ];
    }

    private function findActiveSessionForClient(Client $client, string $currentAttemptMacAddress): ?WifiSession
    {
        return WifiSession::query()
            ->with(['plan:id,name,duration_minutes', 'client:id,name,phone_number'])
            ->where('client_id', $client->id)
            ->whereRaw('LOWER(mac_address) != ?', [strtolower($currentAttemptMacAddress)])
            ->where('is_active', true)
            ->whereNotNull('end_time')
            ->where('end_time', '>', now())
            ->latest('end_time')
            ->first();
    }

    private function lookupConnectedDeviceState(string $macAddress, ?string $requestId = null): ?array
    {
        $settings = ControllerSetting::singleton();

        if (! $settings->canTestConnection()) {
            return null;
        }

        $connectedClients = $this->omadaService->lookupConnectedClientsByMac($settings, [$macAddress], $requestId);
        $normalizedMacAddress = strtoupper($macAddress);

        return $connectedClients[$normalizedMacAddress] ?? null;
    }

    private function transferLockedUntil(Client $client): ?Carbon
    {
        if (! $client->last_transferred_at) {
            return null;
        }

        $cooldownDays = max(0, (int) config('portal.device_transfer_cooldown_days', 7));
        $lockedUntil = $client->last_transferred_at->copy()->addDays($cooldownDays);

        return $lockedUntil->isFuture() ? $lockedUntil : null;
    }

    private function transferActiveSessionToNewDevice(
        Client $client,
        string $newMacAddress,
        array $resolvedContext,
        WifiSession $activeSession,
    ): WifiSession {
        $activeSession = $activeSession->loadMissing(['plan', 'client', 'site', 'accessPoint']);

        if (! $activeSession->end_time || $activeSession->end_time->isPast()) {
            throw new RuntimeException('The active session on the original device is no longer transferable.');
        }

        $newSession = DB::transaction(function () use ($client, $newMacAddress, $resolvedContext, $activeSession): WifiSession {
            return WifiSession::create([
                'client_id' => $client->id,
                'mac_address' => strtolower($newMacAddress),
                'plan_id' => $activeSession->plan_id,
                'site_id' => $resolvedContext['site_id'],
                'access_point_id' => $resolvedContext['access_point_id'],
                'ap_mac' => $resolvedContext['ap_mac'],
                'ap_name' => $resolvedContext['ap_name'],
                'ssid_name' => $resolvedContext['ssid_name'],
                'radio_id' => $resolvedContext['radio_id'] ?? null,
                'client_ip' => $resolvedContext['client_ip'],
                'amount_paid' => $activeSession->amount_paid,
                'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
                'session_status' => WifiSession::SESSION_STATUS_PAID,
                'is_active' => false,
            ]);
        });

        try {
            $activatedSession = $this->authorizePreparedSession(
                $newSession->loadMissing(['site', 'accessPoint', 'plan', 'client']),
                $activeSession->end_time->copy()
            );
        } catch (Throwable $exception) {
            $this->markReleaseFailed($newSession, $exception->getMessage());

            throw new RuntimeException('Transfer failed because the new device could not be authorized.');
        }

        $settings = ControllerSetting::query()->first();

        if ($settings && $settings->hasOpenApiCredentials()) {
            try {
                $this->omadaService->deauthorizeClient($settings, $activeSession);
            } catch (Throwable $exception) {
                Log::warning('Failed to deauthorize old device during transfer.', [
                    'client_id' => $client->id,
                    'old_mac_address' => $activeSession->mac_address,
                    'new_mac_address' => $newMacAddress,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        DB::transaction(function () use ($client, $newMacAddress, $activeSession): void {
            $client->forceFill([
                'mac_address' => strtoupper($newMacAddress),
                'last_connected_at' => now(),
                'last_transferred_at' => now(),
            ])->save();

            $activeSession->forceFill([
                'is_active' => false,
                'end_time' => now(),
                'session_status' => WifiSession::SESSION_STATUS_EXPIRED,
                'release_failure_reason' => 'transferred_to_new_device',
            ])->save();
        });

        return $activatedSession->refresh();
    }

    public function activateSession(WifiSession $session): WifiSession
    {
        if ($session->payment_status !== WifiSession::PAYMENT_STATUS_PAID) {
            throw new \RuntimeException('Session cannot be activated without paid status.');
        }

        if ($session->session_status === WifiSession::SESSION_STATUS_ACTIVE && $session->is_active) {
            return $session;
        }

        $end = Carbon::now()->addMinutes($session->plan->duration_minutes);

        return $this->authorizePreparedSession($session, $end);
    }

    private function authorizePreparedSession(WifiSession $session, Carbon $endTime): WifiSession
    {
        $session->forceFill([
            'start_time' => Carbon::now(),
            'end_time' => $endTime,
            'session_status' => WifiSession::SESSION_STATUS_PAID,
        ])->save();

        $session = $session->loadMissing(['site', 'accessPoint', 'plan', 'client', 'site.operator']);
        $settings = ControllerSetting::query()->first();

        if (! $settings) {
            throw new RuntimeException('Omada controller settings are missing, so the paid client cannot be authorized.');
        }

        // Get operator-specific credentials if available
        $operatorCredentials = null;
        if ($session->site && $session->site->operator) {
            $operatorCredentials = $session->site->operator->credentials()->first();
        }

        try {
            $this->omadaService->authorizeClient($settings, $session, $operatorCredentials);
        } catch (\Exception $e) {
            // If operator credentials fail, fallback to controller admin credentials
            if (strpos($e->getMessage(), 'username or password') !== false || strpos($e->getMessage(), 'Failed to authenticate') !== false) {
                Log::warning('Operator credentials failed, falling back to controller admin', [
                    'session_id' => $session->id,
                    'operator' => $session->site->operator?->business_name,
                    'error' => $e->getMessage(),
                ]);
                
                // Use controller admin credentials as fallback
                $adminCredentials = new \App\Models\OperatorCredential([
                    'hotspot_operator_username' => $settings->username,
                    'hotspot_operator_password' => $settings->password,
                ]);
                
                $this->omadaService->authorizeClient($settings, $session, $adminCredentials);
            } else {
                throw $e;
            }
        }

        $session->forceFill([
            'is_active' => true,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'release_failure_reason' => null,
        ])->save();

        return $session->refresh();
    }

    public function markReleaseFailed(WifiSession $session, string $reason): WifiSession
    {
        $session->forceFill([
            'is_active' => false,
            'start_time' => null,
            'end_time' => null,
            'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
            'release_failure_reason' => $reason,
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
        $session->loadMissing(['site', 'accessPoint', 'plan', 'client', 'site.operator']);

        if ($settings) {
            $deauthorized = false;
            $operatorCredentials = null;
            
            // First try with operator-specific credentials
            if ($session->site && $session->site->operator) {
                $operatorCredentials = $session->site->operator->credentials()->first();
            }

            if ($operatorCredentials) {
                try {
                    $this->omadaService->deauthorizeClient($settings, $session, $operatorCredentials);
                    $deauthorized = true;
                    Log::info('Successfully deauthorized WiFi session using operator credentials.', [
                        'wifi_session_id' => $session->id,
                        'mac_address' => $session->mac_address,
                        'site' => $session->site?->name,
                        'operator' => $session->site?->operator?->business_name,
                    ]);
                } catch (\Throwable $exception) {
                    Log::warning('Failed to deauthorize with operator credentials, falling back to default.', [
                        'wifi_session_id' => $session->id,
                        'client_id' => $session->client_id,
                        'mac_address' => $session->mac_address,
                        'site' => $session->site?->name,
                        'operator' => $session->site?->operator?->business_name,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            // Fall back to default credentials if operator credentials failed or don't exist
            if (!$deauthorized) {
                try {
                    $this->omadaService->deauthorizeClient($settings, $session, null);
                    Log::info('Successfully deauthorized WiFi session using default credentials.', [
                        'wifi_session_id' => $session->id,
                        'mac_address' => $session->mac_address,
                        'site' => $session->site?->name,
                        'operator' => $session->site?->operator?->business_name,
                    ]);
                } catch (\Throwable $exception) {
                    Log::error('Failed to deauthorize expired WiFi session with all credential types.', [
                        'wifi_session_id' => $session->id,
                        'client_id' => $session->client_id,
                        'mac_address' => $session->mac_address,
                        'site' => $session->site?->name,
                        'operator' => $session->site?->operator?->business_name,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $session->forceFill([
            'is_active' => false,
            'session_status' => WifiSession::SESSION_STATUS_EXPIRED,
        ])->save();

        return $session->refresh();
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
                    $this->expireSession($session, $settings);
                    $expiredCount++;
                }
            });

        return $expiredCount;
    }
}
