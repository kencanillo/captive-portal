<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\WifiSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class WifiSessionService
{
    public function __construct(
        private readonly PortalSessionContextResolver $portalSessionContextResolver,
        private readonly OmadaService $omadaService,
    ) {
    }

    public function createSession(string $macAddress, Plan $plan, array $context = [], ?array $clientRegistrationData = null): WifiSession
    {
        return DB::transaction(function () use ($macAddress, $plan, $context, $clientRegistrationData) {
            $resolvedContext = $this->portalSessionContextResolver->resolve($context);
            
            // Find or create client
            $client = $this->findOrCreateClient($macAddress, $clientRegistrationData);

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

    private function findOrCreateClient(string $macAddress, ?array $registrationData): Client
    {
        $client = Client::findByMacAddress($macAddress);

        if ($client) {
            $client->update(['last_connected_at' => now()]);

            return $client;
        }

        if (! $registrationData) {
            throw new \RuntimeException('Client registration data is required for new clients.');
        }

        $existingByPhone = Client::findByPhoneNumber($registrationData['phone_number']);

        if ($existingByPhone) {
            if (! Hash::check($registrationData['pin'], $existingByPhone->pin)) {
                throw new \RuntimeException('The PIN does not match the existing client record for this phone number.');
            }

            $existingByPhone->forceFill([
                'name' => $registrationData['name'] ?: $existingByPhone->name,
                'mac_address' => $macAddress,
                'last_connected_at' => now(),
            ])->save();

            return $existingByPhone->refresh();
        }

        return Client::create([
            'name' => $registrationData['name'],
            'phone_number' => $registrationData['phone_number'],
            'pin' => bcrypt($registrationData['pin']),
            'mac_address' => $macAddress,
            'last_connected_at' => now(),
        ]);
    }

    public function activateSession(WifiSession $session): WifiSession
    {
        if ($session->payment_status !== WifiSession::PAYMENT_STATUS_PAID) {
            throw new \RuntimeException('Session cannot be activated without paid status.');
        }

        if ($session->session_status === WifiSession::SESSION_STATUS_ACTIVE && $session->is_active) {
            return $session;
        }

        $start = Carbon::now();
        $end = $start->copy()->addMinutes($session->plan->duration_minutes);
        $session->forceFill([
            'start_time' => $start,
            'end_time' => $end,
            'session_status' => WifiSession::SESSION_STATUS_PAID,
        ]);

        $session = $session->loadMissing(['site', 'accessPoint', 'plan', 'client']);
        $settings = ControllerSetting::query()->first();

        if (! $settings) {
            throw new \RuntimeException('Omada controller settings are missing, so the paid client cannot be authorized.');
        }

        $this->omadaService->authorizeClient($settings, $session);

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
        $session->loadMissing(['site', 'accessPoint', 'plan', 'client']);

        if ($settings) {
            try {
                $this->omadaService->deauthorizeClient($settings, $session);
            } catch (\Throwable $exception) {
                Log::warning('Failed to deauthorize expired WiFi session in Omada.', [
                    'wifi_session_id' => $session->id,
                    'client_id' => $session->client_id,
                    'mac_address' => $session->mac_address,
                    'error' => $exception->getMessage(),
                ]);
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
