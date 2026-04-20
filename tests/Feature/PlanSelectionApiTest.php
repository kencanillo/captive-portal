<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\Plan;
use App\Models\Site;
use App\Models\WifiSession;
use App\Services\OmadaService;
use App\Support\PortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PlanSelectionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('portal.single_active_device_per_client', false);
        config()->set('portal.bypass_payment', false);
    }

    public function test_it_creates_a_wifi_session_with_access_point_attribution_from_portal_context(): void
    {
        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $portalToken = app(PortalTokenService::class)->issuePortalContextToken([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'site_name' => 'Main Site',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $portalToken,
            'client_registration' => [
                'name' => 'Juan Dela Cruz',
                'phone_number' => '09171234567',
                'pin' => '1234',
                'pin_confirmation' => '1234',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['session_token', 'plan']]);

        $site = Site::query()->firstOrFail();
        $accessPoint = AccessPoint::query()->firstOrFail();
        $session = WifiSession::query()->firstOrFail();

        $this->assertSame('Main Site', $site->name);
        $this->assertSame($site->id, $accessPoint->site_id);
        $this->assertSame('North Pole AP', $accessPoint->name);
        $this->assertSame('11:22:33:44:55:66', $accessPoint->mac_address);
        $this->assertSame($site->id, $session->site_id);
        $this->assertSame($accessPoint->id, $session->access_point_id);
        $this->assertSame('Guest WiFi', $session->ssid_name);
        $this->assertSame(1, $session->radio_id);
        $this->assertSame('192.168.20.10', $session->client_ip);
    }

    public function test_it_requires_a_device_decision_when_the_same_phone_and_pin_are_used_on_a_new_mac(): void
    {
        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => '00:11:22:33:44:55',
            'last_connected_at' => now()->subDay(),
        ]);

        WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mac_address' => '00:11:22:33:44:55',
            'amount_paid' => 24.50,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(50),
        ]);

        $portalToken = app(PortalTokenService::class)->issuePortalContextToken([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'site_name' => 'Main Site',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $portalToken,
            'client_registration' => [
                'name' => 'Juan Dela Cruz',
                'phone_number' => '09171234567',
                'pin' => '1234',
                'pin_confirmation' => '1234',
            ],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.decision.code', 'device_action_required')
            ->assertJsonPath('data.decision.can_transfer', true)
            ->assertJsonPath('data.decision.can_pay', true)
            ->assertJsonPath('data.decision.existing_client.mac_address', '00:11:22:33:44:55');

        $client->refresh();
        $this->assertSame('00:11:22:33:44:55', $client->mac_address);
        $this->assertSame(1, WifiSession::query()->count());
    }

    public function test_it_transfers_an_active_session_to_the_new_device_when_requested(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]);

        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => '00:11:22:33:44:55',
            'last_connected_at' => now()->subDay(),
        ]);

        WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mac_address' => '00:11:22:33:44:55',
            'amount_paid' => 24.50,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(50),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->andReturn([
                'errorCode' => 0,
                'msg' => 'Success.',
            ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $portalToken = app(PortalTokenService::class)->issuePortalContextToken([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'site_name' => 'Main Site',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $portalToken,
            'device_decision' => 'transfer',
            'client_registration' => [
                'name' => 'Juan Dela Cruz',
                'phone_number' => '09171234567',
                'pin' => '1234',
                'pin_confirmation' => '1234',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_required', false)
            ->assertJsonPath('data.session_status', WifiSession::SESSION_STATUS_ACTIVE);

        $client->refresh();

        $this->assertSame('AA:BB:CC:DD:EE:FF', $client->mac_address);
        $this->assertNotNull($client->last_transferred_at);
        $this->assertSame(2, WifiSession::query()->count());
        $this->assertSame(1, WifiSession::query()->where('is_active', true)->count());
        $this->assertSame(1, WifiSession::query()->where('mac_address', 'aa:bb:cc:dd:ee:ff')->where('is_active', true)->count());
    }

    public function test_it_allows_a_second_device_to_pay_with_a_different_pin(): void
    {
        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => '00:11:22:33:44:55',
            'last_connected_at' => now()->subDay(),
        ]);

        $portalToken = app(PortalTokenService::class)->issuePortalContextToken([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'site_name' => 'Main Site',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $portalToken,
            'device_decision' => 'pay',
            'new_pin' => '5678',
            'new_pin_confirmation' => '5678',
            'client_registration' => [
                'name' => 'Juan Dela Cruz',
                'phone_number' => '09171234567',
                'pin' => '1234',
                'pin_confirmation' => '1234',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_required', true)
            ->assertJsonPath('data.payment_status', WifiSession::PAYMENT_STATUS_PENDING);

        $client->refresh();
        $newClient = Client::query()->where('mac_address', 'AA:BB:CC:DD:EE:FF')->firstOrFail();
        $session = WifiSession::query()->latest('id')->firstOrFail();

        $this->assertSame('00:11:22:33:44:55', $client->mac_address);
        $this->assertNotSame($client->id, $newClient->id);
        $this->assertSame('09171234567', $newClient->phone_number);
        $this->assertSame($newClient->id, $session->client_id);
    }

    public function test_it_blocks_transfer_when_the_transfer_cooldown_is_active(): void
    {
        config()->set('portal.device_transfer_cooldown_days', 7);

        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => '00:11:22:33:44:55',
            'last_connected_at' => now()->subDay(),
            'last_transferred_at' => now()->subDays(2),
        ]);

        WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mac_address' => '00:11:22:33:44:55',
            'amount_paid' => 24.50,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(50),
        ]);

        $portalToken = app(PortalTokenService::class)->issuePortalContextToken([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'site_name' => 'Main Site',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $portalToken,
            'client_registration' => [
                'name' => 'Juan Dela Cruz',
                'phone_number' => '09171234567',
                'pin' => '1234',
                'pin_confirmation' => '1234',
            ],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.decision.can_transfer', false)
            ->assertJsonPath('data.decision.transfer_locked_until', $client->last_transferred_at->copy()->addDays(7)->toIso8601String());
    }

    public function test_it_allows_a_new_device_account_when_the_same_phone_number_uses_a_different_pin(): void
    {
        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => '00:11:22:33:44:55',
            'last_connected_at' => now()->subDay(),
        ]);

        $portalToken = app(PortalTokenService::class)->issuePortalContextToken([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'site_name' => 'Main Site',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $portalToken,
            'client_registration' => [
                'name' => 'Juan Dela Cruz',
                'phone_number' => '09171234567',
                'pin' => '9999',
                'pin_confirmation' => '9999',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_required', true)
            ->assertJsonPath('data.payment_status', WifiSession::PAYMENT_STATUS_PENDING);

        $this->assertSame(2, Client::query()->count());
        $this->assertSame(1, WifiSession::query()->count());
        $this->assertDatabaseHas('clients', [
            'phone_number' => '09171234567',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);
    }

    public function test_it_rejects_client_registration_when_pin_confirmation_does_not_match(): void
    {
        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $portalToken = app(PortalTokenService::class)->issuePortalContextToken([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'site_name' => 'Main Site',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $portalToken,
            'client_registration' => [
                'name' => 'Juan Dela Cruz',
                'phone_number' => '09171234567',
                'pin' => '1234',
                'pin_confirmation' => '4321',
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('client_registration.pin_confirmation');
    }
}
