<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CaptivePortalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_preloads_device_context_from_redirect_mac_without_waiting_for_bootstrap(): void
    {
        Client::query()->create([
            'name' => 'Returning Client',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => '0E:9B:B3:1B:8C:4B',
        ]);

        $this->get('/?cid=0E-9B-B3-1B-8C-4B&ap=AC-A7-F1-32-DB-4B&ssid=Juleanne_Coinless_Wifi_Vendo&site=69e31ca32109e3181bab7109')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/PlanSelection')
                ->where('initialDeviceContext.status', 'resolved')
                ->where('initialDeviceContext.resolution_source', 'known_client_db')
                ->where('initialDeviceContext.portal_context.mac_address', '0E:9B:B3:1B:8C:4B')
                ->where('initialDeviceContext.portal_context.ap_mac', 'AC-A7-F1-32-DB-4B')
                ->where('initialDeviceContext.portal_context.ssid_name', 'Juleanne_Coinless_Wifi_Vendo')
                ->where('initialDeviceContext.portal_context.site_identifier', '69e31ca32109e3181bab7109')
                ->where('initialDeviceContext.existing_client.name', 'Returning Client')
                ->where('initialDeviceContext.portal_token', fn ($token) => is_string($token) && $token !== '')
            );
    }

    public function test_it_leaves_device_context_pending_on_initial_page_when_controller_lookup_is_required(): void
    {
        $this->get('/?siteName=North%20Site&clientIp=10.10.10.25')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/PlanSelection')
                ->where('initialDeviceContext.status', 'pending')
                ->where('initialDeviceContext.portal_context.site_name', 'North Site')
                ->where('initialDeviceContext.portal_context.client_ip', '10.10.10.25')
                ->where('initialDeviceContext.portal_token', null)
            );
    }
}
