<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Plan;
use App\Models\WifiSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WifiSessionService
{
    public function __construct(
        private readonly PortalSessionContextResolver $portalSessionContextResolver,
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
                'client_ip' => $resolvedContext['client_ip'],
                'amount_paid' => $plan->price,
                'payment_status' => WifiSession::STATUS_PENDING,
                'is_active' => false,
            ]);
        });
    }

    private function findOrCreateClient(string $macAddress, ?array $registrationData): Client
    {
        // First try to find existing client by MAC address
        $client = Client::findByMacAddress($macAddress);
        
        if ($client) {
            // Update last connected timestamp
            $client->update(['last_connected_at' => now()]);
            return $client;
        }

        // If no registration data, we can't create a new client
        if (!$registrationData) {
            throw new \RuntimeException('Client registration data is required for new clients.');
        }

        // Check if client already exists by phone number
        $existingByPhone = Client::findByPhoneNumber($registrationData['phone_number']);
        if ($existingByPhone) {
            throw new \RuntimeException('A client with this phone number already exists.');
        }

        // Create new client
        return Client::create([
            'name' => $registrationData['name'],
            'phone_number' => $registrationData['phone_number'],
            'pin' => bcrypt($registrationData['pin']), // Hash the PIN
            'mac_address' => $macAddress,
            'last_connected_at' => now(),
        ]);
    }

    public function activateSession(WifiSession $session): WifiSession
    {
        if ($session->payment_status !== WifiSession::STATUS_PAID) {
            throw new \RuntimeException('Session cannot be activated without paid status.');
        }

        $start = Carbon::now();
        $end = $start->copy()->addMinutes($session->plan->duration_minutes);

        $session->forceFill([
            'is_active' => true,
            'start_time' => $start,
            'end_time' => $end,
        ])->save();

        return $session->refresh();
    }

    public function expireSession(WifiSession $session): WifiSession
    {
        $session->forceFill(['is_active' => false])->save();

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
        return WifiSession::query()
            ->where('is_active', true)
            ->whereNotNull('end_time')
            ->where('end_time', '<=', now())
            ->update(['is_active' => false, 'updated_at' => now()]);
    }
}
