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

class ReconcileAuthorizedOmadaClientsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_command_deauthorizes_controller_clients_with_no_valid_local_session(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
            'api_client_id' => 'client-id',
            'api_client_secret' => 'client-secret',
            'site_identifier' => 'main-branch',
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('listAuthorizedClients')
            ->once()
            ->andReturn([
                [
                    'mac_address' => 'aa:bb:cc:dd:ee:ff',
                    'site_identifier' => 'main-branch',
                    'site_name' => 'Main Branch',
                    'ssid_name' => 'Guest WiFi',
                    'authorized' => true,
                    'connected' => true,
                    'raw_status' => 'connected',
                    'raw_portal_status' => 'authorized',
                ],
            ]);
        $omadaService->shouldReceive('deauthorizeClientByMac')
            ->once()
            ->withArgs(fn ($settings, string $macAddress, ?string $siteIdentifier) => $macAddress === 'aa:bb:cc:dd:ee:ff' && $siteIdentifier === 'main-branch')
            ->andReturn(['errorCode' => 0, 'msg' => 'Success.']);

        $this->app->instance(OmadaService::class, $omadaService);

        $this->artisan('omada:reconcile-authorized-clients')
            ->expectsOutput('Reconciled 1 authorized controller client(s): 0 valid, 1 unknown deauthorized, 0 expired deauthorized, 0 invalid local state, 0 failures.')
            ->assertSuccessful();
    }

    public function test_reconciliation_command_keeps_valid_authorized_client_bound_to_its_own_active_session(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
            'api_client_id' => 'client-id',
            'api_client_secret' => 'client-secret',
            'site_identifier' => 'main-branch',
        ]);

        $site = Site::query()->create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
        ]);

        $client = Client::query()->create([
            'name' => 'Valid Client',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Quick Surf 1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $session = WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'amount_paid' => 25,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->addMinutes(55),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('listAuthorizedClients')
            ->once()
            ->andReturn([
                [
                    'mac_address' => 'aa:bb:cc:dd:ee:ff',
                    'site_identifier' => 'main-branch',
                    'site_name' => 'Main Branch',
                    'ssid_name' => 'Guest WiFi',
                    'authorized' => true,
                    'connected' => true,
                    'raw_status' => 'connected',
                    'raw_portal_status' => 'authorized',
                ],
            ]);
        $omadaService->shouldReceive('deauthorizeClientByMac')->never();

        $this->app->instance(OmadaService::class, $omadaService);

        $this->artisan('omada:reconcile-authorized-clients')
            ->expectsOutput('Reconciled 1 authorized controller client(s): 1 valid, 0 unknown deauthorized, 0 expired deauthorized, 0 invalid local state, 0 failures.')
            ->assertSuccessful();

        $this->assertNotNull($session->fresh()->last_controller_seen_at);
        $this->assertNull($session->fresh()->deauthorized_at);
    }

    public function test_reconciliation_command_expires_stale_local_session_and_deauthorizes_controller_client(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
            'api_client_id' => 'client-id',
            'api_client_secret' => 'client-secret',
            'site_identifier' => 'main-branch',
        ]);

        $site = Site::query()->create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
        ]);

        $client = Client::query()->create([
            'name' => 'Expired Client',
            'phone_number' => '09178888888',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);

        $plan = Plan::query()->create([
            'name' => 'Three Minutes',
            'price' => 5,
            'duration_minutes' => 3,
        ]);

        $session = WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'amount_paid' => 5,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->subMinute(),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('listAuthorizedClients')
            ->once()
            ->andReturn([
                [
                    'mac_address' => 'aa:bb:cc:dd:ee:ff',
                    'site_identifier' => 'main-branch',
                    'site_name' => 'Main Branch',
                    'ssid_name' => 'Guest WiFi',
                    'authorized' => true,
                    'connected' => true,
                    'raw_status' => 'connected',
                    'raw_portal_status' => 'authorized',
                ],
            ]);
        $omadaService->shouldReceive('deauthorizeClient')
            ->once()
            ->withArgs(fn ($settings, WifiSession $wifiSession) => $wifiSession->id === $session->id)
            ->andReturn(['errorCode' => 0, 'msg' => 'Success.']);
        $omadaService->shouldReceive('deauthorizeClientByMac')->never();

        $this->app->instance(OmadaService::class, $omadaService);

        $this->artisan('omada:reconcile-authorized-clients')
            ->expectsOutput('Reconciled 1 authorized controller client(s): 0 valid, 0 unknown deauthorized, 1 expired deauthorized, 0 invalid local state, 0 failures.')
            ->assertSuccessful();

        $this->assertFalse($session->fresh()->is_active);
        $this->assertSame(WifiSession::SESSION_STATUS_EXPIRED, $session->fresh()->session_status);
        $this->assertNotNull($session->fresh()->deauthorized_at);
    }
}
