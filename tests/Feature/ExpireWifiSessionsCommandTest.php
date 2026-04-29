<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use App\Services\OmadaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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
            'client_id' => Client::query()->create([
                'name' => 'Maria Clara',
                'phone_number' => '09170000001',
                'pin' => bcrypt('1234'),
                'mac_address' => 'AA:BB:CC:DD:EE:11',
                'last_connected_at' => now(),
            ])->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'mac_address' => 'AA:BB:CC:DD:EE:11',
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

    public function test_failed_expiry_deauthorization_is_retried_by_backup_scheduler(): void
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
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->subMinute(),
            'is_active' => true,
        ]);

        $failingOmadaService = Mockery::mock(OmadaService::class);
        $failingOmadaService->shouldReceive('deauthorizeClient')
            ->once()
            ->andThrow(new \RuntimeException('Omada request failed for [/unauth].'));

        $this->app->instance(OmadaService::class, $failingOmadaService);

        $this->artisan('wifi:expire-sessions')
            ->expectsOutput('Expired 1 session(s).')
            ->assertSuccessful();

        $pendingSession = $expiredSession->fresh();

        $this->assertFalse($pendingSession->is_active);
        $this->assertSame(WifiSession::SESSION_STATUS_EXPIRED, $pendingSession->session_status);
        $this->assertSame('session_expired_local_only', $pendingSession->authorization_source);
        $this->assertNull($pendingSession->deauthorized_at);
        $this->assertSame(WifiSession::CONTROLLER_DEAUTH_STATUS_FAILED, $pendingSession->controller_deauthorization_status);
        $this->assertSame(1, $pendingSession->controller_deauthorization_attempt_count);
        $this->assertNotNull($pendingSession->controller_deauthorization_next_attempt_at);
        $this->assertSame('Omada request failed for [/unauth].', $pendingSession->controller_deauthorization_last_error);
        $this->assertNull($pendingSession->last_reconcile_result);
        $this->assertSame(0, $pendingSession->reconcile_attempt_count);

        $this->travel(2)->minutes();

        $recoveringOmadaService = Mockery::mock(OmadaService::class);
        $recoveringOmadaService->shouldReceive('deauthorizeClient')
            ->once()
            ->withArgs(fn (ControllerSetting $settings, WifiSession $session): bool => $session->id === $expiredSession->id)
            ->andReturn([
                'errorCode' => 0,
                'msg' => 'Success.',
            ]);

        $this->app->instance(OmadaService::class, $recoveringOmadaService);

        $this->artisan('wifi:retry-deauthorizations')
            ->expectsOutput('Retried 1 pending deauthorization(s): 1 succeeded, 0 failed, 0 manual required.')
            ->assertSuccessful();

        $retriedSession = $expiredSession->fresh();

        $this->assertSame('session_expired_retry', $retriedSession->authorization_source);
        $this->assertNotNull($retriedSession->deauthorized_at);
        $this->assertSame(WifiSession::CONTROLLER_DEAUTH_STATUS_SUCCEEDED, $retriedSession->controller_deauthorization_status);
        $this->assertSame(2, $retriedSession->controller_deauthorization_attempt_count);
        $this->assertNull($retriedSession->controller_deauthorization_next_attempt_at);
        $this->assertNull($retriedSession->controller_deauthorization_last_error);
        $this->assertNull($retriedSession->last_reconcile_result);
        $this->assertSame(0, $retriedSession->reconcile_attempt_count);
    }

    public function test_retry_scheduler_records_row_failure_without_failing_the_command(): void
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
            'session_status' => WifiSession::SESSION_STATUS_EXPIRED,
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->subMinute(),
            'is_active' => false,
            'authorization_source' => 'session_expired_local_only',
            'controller_deauthorization_status' => WifiSession::CONTROLLER_DEAUTH_STATUS_FAILED,
            'controller_deauthorization_attempt_count' => 1,
            'controller_deauthorization_last_attempt_at' => now()->subMinutes(2),
            'controller_deauthorization_next_attempt_at' => now()->subMinute(),
            'controller_deauthorization_last_error' => 'Previous failure.',
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('deauthorizeClient')
            ->once()
            ->withArgs(fn (ControllerSetting $settings, WifiSession $session): bool => $session->id === $expiredSession->id)
            ->andThrow(new \RuntimeException('Controller still rejected unauth.'));

        $this->app->instance(OmadaService::class, $omadaService);

        $this->artisan('wifi:retry-deauthorizations')
            ->expectsOutput('Retried 1 pending deauthorization(s): 0 succeeded, 1 failed, 0 manual required.')
            ->assertSuccessful();

        $failedSession = $expiredSession->fresh();

        $this->assertNull($failedSession->deauthorized_at);
        $this->assertSame(WifiSession::CONTROLLER_DEAUTH_STATUS_FAILED, $failedSession->controller_deauthorization_status);
        $this->assertSame(2, $failedSession->controller_deauthorization_attempt_count);
        $this->assertTrue($failedSession->controller_deauthorization_next_attempt_at->gt(now()));
        $this->assertSame('Controller still rejected unauth.', $failedSession->controller_deauthorization_last_error);
        $this->assertNull($failedSession->last_reconcile_result);
        $this->assertSame(0, $failedSession->reconcile_attempt_count);

        $skippedOmadaService = Mockery::mock(OmadaService::class);
        $skippedOmadaService->shouldReceive('deauthorizeClient')->never();

        $this->app->instance(OmadaService::class, $skippedOmadaService);

        $this->artisan('wifi:retry-deauthorizations')
            ->expectsOutput('Retried 0 pending deauthorization(s): 0 succeeded, 0 failed, 0 manual required.')
            ->assertSuccessful();
    }

    public function test_manual_required_deauthorization_is_visible_in_admin_sessions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
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
            'name' => '3 Hours',
            'price' => 25,
            'duration_minutes' => 180,
        ]);

        $session = WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'mac_address' => $client->mac_address,
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_EXPIRED,
            'release_status' => WifiSession::RELEASE_STATUS_SUCCEEDED,
            'start_time' => now()->subHours(4),
            'end_time' => now()->subHour(),
            'is_active' => false,
            'authorization_source' => 'session_expired_local_only',
            'controller_deauthorization_status' => WifiSession::CONTROLLER_DEAUTH_STATUS_MANUAL_REQUIRED,
            'controller_deauthorization_attempt_count' => 20,
            'controller_deauthorization_last_attempt_at' => now()->subMinute(),
            'controller_deauthorization_next_attempt_at' => null,
            'controller_deauthorization_last_error' => 'Omada rejected unauth until manual reconnect was performed.',
        ]);

        $this->actingAs($admin)
            ->get('/admin/sessions')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Sessions')
                ->where('sessions.data.0.id', $session->id)
                ->where('sessions.data.0.controller_deauthorization_status', WifiSession::CONTROLLER_DEAUTH_STATUS_MANUAL_REQUIRED)
                ->where('sessions.data.0.controller_deauthorization_attempt_count', 20)
                ->where('sessions.data.0.controller_deauthorization_last_error', 'Omada rejected unauth until manual reconnect was performed.')
                ->where('sessions.data.0.manual_controller_deauthorization_required', true)
            );
    }
}
