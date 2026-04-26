<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientDevice;
use App\Models\ControllerSetting;
use App\Models\DeviceTransferRequest;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use App\Services\OmadaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DeviceTransferRequestAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_approval_moves_entitlement_to_new_device_without_creating_second_active_session(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'api_client_id' => 'client-id',
            'api_client_secret' => 'client-secret',
        ]);

        [$client, $fromDevice, $activeSession, $transferRequest, $originalEndTime] = $this->createPendingTransferRequest();

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('deauthorizeClient')->once()->andReturn(['errorCode' => 0]);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->withArgs(fn ($settings, WifiSession $session) => strtolower($session->mac_address) === 'aa:bb:cc:dd:ee:ff')
            ->andReturn(['errorCode' => 0]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($admin)
            ->post("/admin/transfer-requests/{$transferRequest->id}/approve", [
                'review_notes' => 'Verified replacement handset.',
            ])
            ->assertRedirect('/admin/transfer-requests');

        $transferRequest->refresh();
        $activeSession->refresh();
        $client->refresh();

        $this->assertSame(DeviceTransferRequest::STATUS_EXECUTED, $transferRequest->status);
        $this->assertSame($admin->id, $transferRequest->reviewed_by_user_id);
        $this->assertNotNull($transferRequest->reviewed_at);
        $this->assertNotNull($transferRequest->executed_at);
        $this->assertSame('Verified replacement handset.', $transferRequest->review_notes);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $activeSession->mac_address);
        $this->assertSame($originalEndTime->format('Y-m-d H:i:s'), $activeSession->end_time->format('Y-m-d H:i:s'));
        $this->assertTrue($activeSession->is_active);
        $this->assertSame(1, WifiSession::query()->where('client_id', $client->id)->where('is_active', true)->count());
        $this->assertSame(0, WifiSession::query()->where('client_id', $client->id)->where('mac_address', '00:11:22:33:44:55')->where('is_active', true)->count());
        $this->assertSame('aa:bb:cc:dd:ee:ff', $client->mac_address);
        $this->assertDatabaseHas('client_devices', [
            'client_id' => $client->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);
        $this->assertSame('00:11:22:33:44:55', $fromDevice->fresh()->mac_address);
        $this->assertSame('replaced', $fromDevice->fresh()->status);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $transferRequest->execution_metadata['to_mac_address']);
    }

    public function test_admin_denial_leaves_active_entitlement_unchanged(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$client, $fromDevice, $activeSession, $transferRequest, $originalEndTime] = $this->createPendingTransferRequest();

        $this->actingAs($admin)
            ->post("/admin/transfer-requests/{$transferRequest->id}/deny", [
                'denial_reason' => 'Identity mismatch.',
                'review_notes' => 'Support could not verify ownership.',
            ])
            ->assertRedirect('/admin/transfer-requests');

        $transferRequest->refresh();
        $activeSession->refresh();
        $client->refresh();

        $this->assertSame(DeviceTransferRequest::STATUS_DENIED, $transferRequest->status);
        $this->assertSame($admin->id, $transferRequest->reviewed_by_user_id);
        $this->assertSame('Identity mismatch.', $transferRequest->denial_reason);
        $this->assertSame('Support could not verify ownership.', $transferRequest->review_notes);
        $this->assertSame('00:11:22:33:44:55', $activeSession->mac_address);
        $this->assertSame($originalEndTime->format('Y-m-d H:i:s'), $activeSession->end_time->format('Y-m-d H:i:s'));
        $this->assertSame('00:11:22:33:44:55', $client->mac_address);
        $this->assertSame('00:11:22:33:44:55', $fromDevice->fresh()->mac_address);
        $this->assertSame('bound', $fromDevice->fresh()->status);
    }

    public function test_failed_transfer_execution_leaves_old_entitlement_and_device_unchanged(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'api_client_id' => 'client-id',
            'api_client_secret' => 'client-secret',
        ]);

        [$client, $fromDevice, $activeSession, $transferRequest, $originalEndTime] = $this->createPendingTransferRequest();

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('deauthorizeClient')->once()->andReturn(['errorCode' => 0]);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->withArgs(fn ($settings, WifiSession $session) => strtolower($session->mac_address) === 'aa:bb:cc:dd:ee:ff')
            ->andThrow(new \RuntimeException('New device authorization failed.'));
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->withArgs(fn ($settings, WifiSession $session) => strtolower($session->mac_address) === '00:11:22:33:44:55')
            ->andReturn(['errorCode' => 0]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($admin)
            ->post("/admin/transfer-requests/{$transferRequest->id}/approve", [
                'review_notes' => 'Attempted transfer.',
            ])
            ->assertRedirect('/admin/transfer-requests');

        $transferRequest->refresh();
        $activeSession->refresh();
        $client->refresh();

        $this->assertSame(DeviceTransferRequest::STATUS_FAILED, $transferRequest->status);
        $this->assertSame('New device authorization failed.', $transferRequest->failure_reason);
        $this->assertSame('00:11:22:33:44:55', $activeSession->mac_address);
        $this->assertSame($originalEndTime->format('Y-m-d H:i:s'), $activeSession->end_time->format('Y-m-d H:i:s'));
        $this->assertSame('00:11:22:33:44:55', $client->mac_address);
        $this->assertSame('00:11:22:33:44:55', $fromDevice->fresh()->mac_address);
        $this->assertSame('bound', $fromDevice->fresh()->status);
        $this->assertTrue($transferRequest->execution_metadata['old_device_restore_attempted']);
        $this->assertTrue($transferRequest->execution_metadata['old_device_restore_succeeded']);
        $this->assertFalse($transferRequest->execution_metadata['controller_state_uncertain']);
    }

    public function test_failed_transfer_with_rollback_restore_failure_is_support_visible(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'api_client_id' => 'client-id',
            'api_client_secret' => 'client-secret',
        ]);

        [$client, $fromDevice, $activeSession, $transferRequest, $originalEndTime] = $this->createPendingTransferRequest();

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('deauthorizeClient')->once()->andReturn(['errorCode' => 0]);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->withArgs(fn ($settings, WifiSession $session) => strtolower($session->mac_address) === 'aa:bb:cc:dd:ee:ff')
            ->andThrow(new \RuntimeException('New device authorization failed.'));
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->withArgs(fn ($settings, WifiSession $session) => strtolower($session->mac_address) === '00:11:22:33:44:55')
            ->andThrow(new \RuntimeException('Old device rollback failed.'));
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($admin)
            ->post("/admin/transfer-requests/{$transferRequest->id}/approve", [
                'review_notes' => 'Attempted transfer.',
            ])
            ->assertRedirect('/admin/transfer-requests');

        $transferRequest->refresh();
        $activeSession->refresh();
        $client->refresh();

        $this->assertSame(DeviceTransferRequest::STATUS_FAILED, $transferRequest->status);
        $this->assertStringContainsString('New device authorization failed.', $transferRequest->failure_reason);
        $this->assertStringContainsString('Manual controller follow-up is required.', $transferRequest->failure_reason);
        $this->assertSame('00:11:22:33:44:55', $activeSession->mac_address);
        $this->assertSame($originalEndTime->format('Y-m-d H:i:s'), $activeSession->end_time->format('Y-m-d H:i:s'));
        $this->assertSame('00:11:22:33:44:55', $client->mac_address);
        $this->assertSame('00:11:22:33:44:55', $fromDevice->fresh()->mac_address);
        $this->assertSame('bound', $fromDevice->fresh()->status);
        $this->assertTrue($transferRequest->execution_metadata['deauthorized_old_device']);
        $this->assertTrue($transferRequest->execution_metadata['old_device_restore_attempted']);
        $this->assertFalse($transferRequest->execution_metadata['old_device_restore_succeeded']);
        $this->assertTrue($transferRequest->execution_metadata['controller_state_uncertain']);
    }

    public function test_transfer_approval_fails_when_target_mac_is_already_bound_elsewhere(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$client, , $activeSession, $transferRequest, $originalEndTime] = $this->createPendingTransferRequest();

        $otherClient = Client::query()->create([
            'name' => 'Other Client',
            'phone_number' => '09179999999',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'last_connected_at' => now(),
        ]);

        ClientDevice::query()->create([
            'client_id' => $otherClient->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'bound',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/transfer-requests/{$transferRequest->id}/approve")
            ->assertRedirect('/admin/transfer-requests');

        $transferRequest->refresh();
        $activeSession->refresh();
        $client->refresh();

        $this->assertSame(DeviceTransferRequest::STATUS_FAILED, $transferRequest->status);
        $this->assertSame('Transfer target is already bound to another account.', $transferRequest->failure_reason);
        $this->assertSame('00:11:22:33:44:55', $activeSession->mac_address);
        $this->assertSame($originalEndTime->format('Y-m-d H:i:s'), $activeSession->end_time->format('Y-m-d H:i:s'));
        $this->assertSame('00:11:22:33:44:55', $client->mac_address);
    }

    public function test_transfer_approval_fails_when_active_entitlement_no_longer_exists(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$client, , $activeSession, $transferRequest] = $this->createPendingTransferRequest();

        $activeSession->update([
            'is_active' => false,
            'end_time' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/transfer-requests/{$transferRequest->id}/approve")
            ->assertRedirect('/admin/transfer-requests');

        $transferRequest->refresh();
        $client->refresh();

        $this->assertSame(DeviceTransferRequest::STATUS_FAILED, $transferRequest->status);
        $this->assertSame('Transfer cannot proceed because the active entitlement is no longer valid.', $transferRequest->failure_reason);
        $this->assertSame('00:11:22:33:44:55', $client->mac_address);
    }

    public function test_legacy_client_mac_address_does_not_override_client_devices_during_transfer(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'api_client_id' => 'client-id',
            'api_client_secret' => 'client-secret',
        ]);

        [$client, , $activeSession, $transferRequest] = $this->createPendingTransferRequest();
        $client->update(['mac_address' => 'ff:ff:ff:ff:ff:ff']);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('deauthorizeClient')->once()->andReturn(['errorCode' => 0]);
        $omadaService->shouldReceive('authorizeClient')->once()->andReturn(['errorCode' => 0]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($admin)
            ->post("/admin/transfer-requests/{$transferRequest->id}/approve")
            ->assertRedirect('/admin/transfer-requests');

        $activeSession->refresh();
        $client->refresh();

        $this->assertSame(DeviceTransferRequest::STATUS_EXECUTED, $transferRequest->fresh()->status);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $activeSession->mac_address);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $client->mac_address);
    }

    public function test_duplicate_admin_approval_attempt_cannot_execute_twice(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'api_client_id' => 'client-id',
            'api_client_secret' => 'client-secret',
        ]);

        [$client, $fromDevice, $activeSession, $transferRequest] = $this->createPendingTransferRequest();

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('deauthorizeClient')->once()->andReturn(['errorCode' => 0]);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->withArgs(fn ($settings, WifiSession $session) => strtolower($session->mac_address) === 'aa:bb:cc:dd:ee:ff')
            ->andReturn(['errorCode' => 0]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($admin)
            ->post("/admin/transfer-requests/{$transferRequest->id}/approve")
            ->assertRedirect('/admin/transfer-requests');

        $this->actingAs($admin)
            ->post("/admin/transfer-requests/{$transferRequest->id}/approve")
            ->assertRedirect('/admin/transfer-requests')
            ->assertSessionHas('error', 'Only pending transfer requests can be approved.');

        $transferRequest->refresh();
        $activeSession->refresh();

        $this->assertSame(DeviceTransferRequest::STATUS_EXECUTED, $transferRequest->status);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $activeSession->mac_address);
        $this->assertSame(1, WifiSession::query()->where('client_id', $client->id)->where('is_active', true)->count());
        $this->assertSame('replaced', $fromDevice->fresh()->status);
    }

    public function test_transfer_admin_routes_require_admin_access(): void
    {
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        [, , , $transferRequest] = $this->createPendingTransferRequest();

        $this->get('/admin/transfer-requests')
            ->assertRedirect('/login');

        $this->actingAs($nonAdmin)
            ->get('/admin/transfer-requests')
            ->assertForbidden();

        $this->actingAs($nonAdmin)
            ->post("/admin/transfer-requests/{$transferRequest->id}/approve")
            ->assertForbidden();

        $this->actingAs($nonAdmin)
            ->post("/admin/transfer-requests/{$transferRequest->id}/deny", [
                'denial_reason' => 'No access.',
            ])
            ->assertForbidden();
    }

    private function createPendingTransferRequest(): array
    {
        $site = Site::query()->create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
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
            'last_connected_at' => now(),
        ]);

        $fromDevice = ClientDevice::query()->create([
            'client_id' => $client->id,
            'mac_address' => '00:11:22:33:44:55',
            'status' => 'bound',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        $originalEndTime = now()->addMinutes(35);

        $activeSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'client_device_id' => $fromDevice->id,
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'mac_address' => '00:11:22:33:44:55',
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(25),
            'end_time' => $originalEndTime,
        ]);

        $transferRequest = DeviceTransferRequest::query()->create([
            'client_id' => $client->id,
            'active_wifi_session_id' => $activeSession->id,
            'from_client_device_id' => $fromDevice->id,
            'requested_mac_address' => 'aa:bb:cc:dd:ee:ff',
            'requested_phone_number' => '09171234567',
            'status' => DeviceTransferRequest::STATUS_PENDING_REVIEW,
            'requested_at' => now(),
            'metadata' => [
                'requested_via' => 'portal',
                'request_ip' => '192.168.20.10',
            ],
        ]);

        return [$client, $fromDevice, $activeSession, $transferRequest, $originalEndTime];
    }
}
