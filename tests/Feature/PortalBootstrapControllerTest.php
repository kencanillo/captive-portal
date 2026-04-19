<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ControllerSetting;
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
        $omadaService->shouldReceive('getClientMacAddress')
            ->once()
            ->withArgs(function (ControllerSetting $settings, string $clientIp): bool {
                return $settings->base_url === 'https://controller.example.com'
                    && $clientIp === '10.10.10.25';
            })
            ->andReturn('aa:bb:cc:dd:ee:ff');
        $this->app->instance(OmadaService::class, $omadaService);

        $this->getJson('/api/portal/bootstrap?clientMac=11:22:33:44:55:66&clientIp=10.10.10.25&siteName=North%20Site')
            ->assertOk()
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

        $this->getJson('/api/portal/bootstrap?clientMac=aa-bb-cc-dd-ee-ff&clientIp=10.10.10.26')
            ->assertOk()
            ->assertJsonPath('data.portal_context.mac_address', 'AA:BB:CC:DD:EE:FF')
            ->assertJsonPath('data.portal_context.client_ip', '10.10.10.26')
            ->assertJsonPath('data.existing_client.name', 'Fallback Client');
    }
}
