<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\Plan;
use App\Models\Site;
use App\Models\WifiSession;
use App\Services\OmadaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ExpireWifiSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deauthorizes_due_sessions_in_omada_before_marking_them_inactive(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]);

        $site = Site::query()->create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
        ]);

        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'last_connected_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'name' => '3 Minutes',
            'price' => 5,
            'duration_minutes' => 3,
        ]);

        $expiredSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'mac_address' => $client->mac_address,
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'ssid_name' => 'KRC_Coinless_Wifi_VEndo',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::STATUS_PAID,
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->subMinute(),
            'is_active' => true,
        ]);

        $activeSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'mac_address' => $client->mac_address,
            'ap_mac' => '11:22:33:44:55:67',
            'ap_name' => 'South Pole AP',
            'ssid_name' => 'KRC_Coinless_Wifi_VEndo',
            'radio_id' => 2,
            'client_ip' => '192.168.20.11',
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::STATUS_PAID,
            'start_time' => now()->subMinute(),
            'end_time' => now()->addMinutes(2),
            'is_active' => true,
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('deauthorizeClient')
            ->once()
            ->withArgs(function (ControllerSetting $settings, WifiSession $session) use ($expiredSession): bool {
                return $settings->hotspot_operator_username === 'operator'
                    && $session->id === $expiredSession->id
                    && $session->radio_id === 1
                    && $session->ssid_name === 'KRC_Coinless_Wifi_VEndo';
            })
            ->andReturn([
                'errorCode' => 0,
                'msg' => 'Success.',
            ]);

        $this->app->instance(OmadaService::class, $omadaService);

        $this->artisan('wifi:expire-sessions')
            ->expectsOutput('Expired 1 session(s).')
            ->assertSuccessful();

        $this->assertFalse($expiredSession->fresh()->is_active);
        $this->assertTrue($activeSession->fresh()->is_active);
    }
}
