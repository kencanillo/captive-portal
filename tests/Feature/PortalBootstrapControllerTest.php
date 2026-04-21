<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\Plan;
use App\Models\WifiSession;
use App\Services\OmadaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PortalBootstrapControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_bootstrap_endpoint_uses_omada_mac_and_returns_async_context_without_plans(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Primary Controller',
            'base_url' => 'https://controller.example.com',
            'api_client_id' => 'client-id',
            'api_client_secret' => 'client-secret',
        ]);

        Client::query()->create([
            'name' => 'Returning Client',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('lookupPortalClientContext')
            ->once()
            ->withArgs(function (ControllerSetting $settings, string $clientIp, ?string $requestId = null): bool {
                return $settings->base_url === 'https://controller.example.com'
                    && $clientIp === '10.10.10.25'
                    && filled($requestId);
            })
            ->andReturn([
                'status' => 'resolved',
                'resolution_source' => 'omada',
                'mac_address' => 'aa:bb:cc:dd:ee:ff',
                'error_code' => null,
                'retry_after_ms' => 0,
            ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->getJson('/api/portal/bootstrap?clientMac=11:22:33:44:55:66&clientIp=10.10.10.25&siteName=North%20Site')
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.portal_context.mac_address', 'aa:bb:cc:dd:ee:ff')
            ->assertJsonPath('data.portal_context.client_ip', '10.10.10.25')
            ->assertJsonPath('data.portal_context.site_name', 'North Site')
            ->assertJsonPath('data.existing_client.name', 'Returning Client')
            ->assertJsonMissingPath('data.plans');
    }

    public function test_portal_bootstrap_can_fallback_to_query_mac_when_enabled(): void
    {
        config()->set('portal.allow_query_mac_fallback', true);

        Client::query()->create([
            'name' => 'Fallback Client',
            'phone_number' => '09179999999',
            'pin' => bcrypt('1234'),
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldNotReceive('getClientMacAddress');
        $this->app->instance(OmadaService::class, $omadaService);

        $this->getJson('/api/portal/bootstrap?clientMac=aa-bb-cc-dd-ee-ff&clientIp=10.10.10.26')
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.portal_context.mac_address', 'AA:BB:CC:DD:EE:FF')
            ->assertJsonPath('data.portal_context.client_ip', '10.10.10.26')
            ->assertJsonPath('data.existing_client.name', 'Fallback Client');
    }

    public function test_portal_bootstrap_uses_known_client_mac_from_database_before_calling_omada(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Primary Controller',
            'base_url' => 'https://controller.example.com',
            'api_client_id' => 'client-id',
            'api_client_secret' => 'client-secret',
        ]);

        Client::query()->create([
            'name' => 'Known Client',
            'phone_number' => '09171111111',
            'pin' => bcrypt('1234'),
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldNotReceive('getClientMacAddress');
        $this->app->instance(OmadaService::class, $omadaService);

        $this->getJson('/api/portal/bootstrap?clientMac=aa-bb-cc-dd-ee-ff&clientIp=10.10.10.27')
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.portal_context.mac_address', 'AA:BB:CC:DD:EE:FF')
            ->assertJsonPath('data.existing_client.name', 'Known Client');
    }

    public function test_portal_bootstrap_returns_active_session_details_for_connected_clients(): void
    {
        config()->set('portal.allow_query_mac_fallback', true);

        $client = Client::query()->create([
            'name' => 'Connected Client',
            'phone_number' => '09175555555',
            'pin' => bcrypt('1234'),
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
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

        $response = $this->getJson('/api/portal/bootstrap?clientMac=AA:BB:CC:DD:EE:FF');

        $response->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.active_session.client_name', 'Connected Client')
            ->assertJsonPath('data.active_session.plan.name', 'Quick Surf 1 Hour')
            ->assertJsonPath('data.active_session.session_status', WifiSession::SESSION_STATUS_ACTIVE);
    }

    public function test_portal_device_context_endpoint_returns_retryable_status_when_omada_has_not_seen_client_yet(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Primary Controller',
            'base_url' => 'https://controller.example.com',
            'api_client_id' => 'client-id',
            'api_client_secret' => 'client-secret',
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('lookupPortalClientContext')
            ->once()
            ->andReturn([
                'status' => 'retryable',
                'resolution_source' => 'omada_not_found',
                'mac_address' => null,
                'error_code' => 'not_found',
                'retry_after_ms' => 1500,
            ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->getJson('/api/portal/device-context?clientIp=10.10.10.99&siteName=North%20Site')
            ->assertOk()
            ->assertJsonPath('data.status', 'retryable')
            ->assertJsonPath('data.error_code', 'not_found')
            ->assertJsonPath('data.portal_context.client_ip', '10.10.10.99')
            ->assertJsonPath('data.portal_context.site_name', 'North Site')
            ->assertJsonPath('data.portal_context.mac_address', null)
            ->assertJsonPath('data.portal_token', null);
    }

    public function test_portal_bootstrap_does_not_trust_recent_sessions_by_ip_for_account_resolution(): void
    {
        $client = Client::query()->create([
            'name' => 'Recent Session Client',
            'phone_number' => '09170000000',
            'pin' => bcrypt('1234'),
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
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
            'client_ip' => '10.10.10.45',
            'amount_paid' => 25,
            'payment_status' => WifiSession::PAYMENT_STATUS_PENDING,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->getJson('/api/portal/bootstrap?clientIp=10.10.10.45')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_code', 'controller_unavailable')
            ->assertJsonPath('data.portal_context.client_ip', '10.10.10.45')
            ->assertJsonPath('data.portal_context.mac_address', null)
            ->assertJsonPath('data.existing_client', null)
            ->assertJsonPath('data.active_session', null);
    }
}
