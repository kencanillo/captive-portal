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
            ->andReturn('aa:bb:cc:dd:ee:ff');
        $this->app->instance(OmadaService::class, $omadaService);

        $this->getJson('/api/portal/bootstrap?clientMac=11:22:33:44:55:66&siteName=North%20Site')
            ->assertOk()
            ->assertJsonPath('data.portal_context.mac_address', 'aa:bb:cc:dd:ee:ff')
            ->assertJsonPath('data.portal_context.site_name', 'North Site')
            ->assertJsonPath('data.existing_client.name', 'Returning Client')
            ->assertJsonMissingPath('data.plans');
    }
}
