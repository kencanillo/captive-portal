<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\WifiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalBootstrapControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_visit_without_captive_portal_context_does_not_expose_another_clients_active_session(): void
    {
        $client = Client::query()->create([
            'name' => 'Charmine',
            'phone_number' => '09175555555',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Quick Surf 1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'amount_paid' => 25,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(50),
        ]);

        $this->getJson('/api/portal/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_code', 'missing_captive_context')
            ->assertJsonPath('data.portal_context.mac_address', null)
            ->assertJsonPath('data.existing_client', null)
            ->assertJsonPath('data.active_session', null)
            ->assertJsonPath('data.portal_token', null);
    }

    public function test_request_with_client_mac_only_resolves_the_session_for_that_exact_mac(): void
    {
        $client = Client::query()->create([
            'name' => 'Connected Client',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Quick Surf 1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'amount_paid' => 25,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(50),
        ]);

        $this->getJson('/api/portal/bootstrap?clientMac=aa-bb-cc-dd-ee-ff')
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.portal_context.mac_address', 'AA:BB:CC:DD:EE:FF')
            ->assertJsonPath('data.existing_client.name', 'Connected Client')
            ->assertJsonPath('data.active_session.client_name', 'Connected Client')
            ->assertJsonPath('data.active_session.plan.name', 'Quick Surf 1 Hour')
            ->assertJsonPath('data.active_session.session_status', WifiSession::SESSION_STATUS_ACTIVE);
    }

    public function test_different_mac_cannot_inherit_another_devices_session(): void
    {
        $client = Client::query()->create([
            'name' => 'Charmine',
            'phone_number' => '09170000000',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Quick Surf 1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'amount_paid' => 25,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(50),
        ]);

        $this->getJson('/api/portal/bootstrap?clientMac=11-22-33-44-55-66')
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.portal_context.mac_address', '11:22:33:44:55:66')
            ->assertJsonPath('data.existing_client', null)
            ->assertJsonPath('data.active_session', null);
    }

    public function test_mac_normalization_is_consistent_across_client_mac_variants(): void
    {
        $client = Client::query()->create([
            'name' => 'Normalized Client',
            'phone_number' => '09179999999',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Quick Surf 30',
            'price' => 15,
            'duration_minutes' => 30,
        ]);

        WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'amount_paid' => 15,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->addMinutes(25),
        ]);

        $this->getJson('/api/portal/bootstrap?clientMac=AABBCCDDEEFF')
            ->assertOk()
            ->assertJsonPath('data.portal_context.mac_address', 'AA:BB:CC:DD:EE:FF')
            ->assertJsonPath('data.active_session.client_name', 'Normalized Client');
    }
}
