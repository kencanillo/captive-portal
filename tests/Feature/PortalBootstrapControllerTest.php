<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalBootstrapControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_bootstrap_endpoint_returns_async_context_and_plans(): void
    {
        Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
            'is_active' => true,
        ]);
        Client::query()->create([
            'name' => 'Returning Client',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $this->getJson('/api/portal/bootstrap?clientMac=aa:bb:cc:dd:ee:ff&siteName=North%20Site')
            ->assertOk()
            ->assertJsonPath('data.portal_context.mac_address', 'aa:bb:cc:dd:ee:ff')
            ->assertJsonPath('data.portal_context.site_name', 'North Site')
            ->assertJsonPath('data.existing_client.name', 'Returning Client')
            ->assertJsonCount(1, 'data.plans');
    }
}
