<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Site;
use App\Models\WifiSession;
use App\Support\PortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanSelectionApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_relinks_an_existing_client_by_phone_number_when_the_pin_matches(): void
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
            'client_registration' => [
                'name' => 'Juan Dela Cruz',
                'phone_number' => '09171234567',
                'pin' => '1234',
                'pin_confirmation' => '1234',
            ],
        ]);

        $response->assertCreated();

        $client->refresh();
        $session = WifiSession::query()->firstOrFail();

        $this->assertSame('AA:BB:CC:DD:EE:FF', $client->mac_address);
        $this->assertSame($client->id, $session->client_id);
    }

    public function test_it_rejects_relinking_an_existing_client_when_the_pin_does_not_match(): void
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

        $response->assertStatus(422)
            ->assertJsonValidationErrors('client_registration.pin')
            ->assertJsonFragment([
                'The PIN does not match the existing client record for this phone number.',
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
