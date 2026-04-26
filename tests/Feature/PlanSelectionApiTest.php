<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Client;
use App\Models\ClientDevice;
use App\Models\DeviceTransferRequest;
use App\Models\Plan;
use App\Models\Site;
use App\Models\WifiSession;
use Illuminate\Database\QueryException;
use App\Support\PortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanSelectionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_wifi_session_with_access_point_attribution_from_portal_context(): void
    {
        $plan = $this->createPlan();
        $portalToken = $this->issuePortalToken('AA:BB:CC:DD:EE:FF');

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $portalToken,
            'client_registration' => $this->registrationPayload(),
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
        $this->assertTrue($accessPoint->is_online);
        $this->assertSame(AccessPoint::HEALTH_STATE_CONNECTED, $accessPoint->health_state);
        $this->assertSame($site->id, $session->site_id);
        $this->assertSame($accessPoint->id, $session->access_point_id);
        $this->assertSame('Guest WiFi', $session->ssid_name);
        $this->assertSame(1, $session->radio_id);
        $this->assertSame('192.168.20.10', $session->client_ip);
        $this->assertNotNull($session->client_device_id);
    }

    public function test_existing_account_purchase_requires_pin_even_when_mac_is_recognized(): void
    {
        $plan = $this->createPlan();

        Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'last_connected_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $this->issuePortalToken('AA:BB:CC:DD:EE:FF'),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('client_registration');
    }

    public function test_phone_and_pin_on_different_mac_does_not_silently_rebind(): void
    {
        $plan = $this->createPlan();
        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => '00:11:22:33:44:55',
            'last_connected_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $this->issuePortalToken('AA:BB:CC:DD:EE:FF'),
            'client_registration' => $this->registrationPayload(),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.code', 'transfer_required')
            ->assertJsonPath('data.has_active_entitlement', false)
            ->assertJsonPath('data.masked_phone_number', '*******4567')
            ->assertJsonMissingPath('data.client')
            ->assertJsonMissingPath('data.active_session');

        $client->refresh();

        $this->assertSame('00:11:22:33:44:55', $client->mac_address);
        $this->assertSame(0, WifiSession::query()->count());
    }

    public function test_different_mac_with_active_remaining_time_returns_transfer_required(): void
    {
        $plan = $this->createPlan();
        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => '00:11:22:33:44:55',
            'last_connected_at' => now()->subDay(),
        ]);

        $activeSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'mac_address' => '00:11:22:33:44:55',
            'plan_id' => $plan->id,
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(50),
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $this->issuePortalToken('AA:BB:CC:DD:EE:FF'),
            'client_registration' => $this->registrationPayload(),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.code', 'transfer_required')
            ->assertJsonPath('data.has_active_entitlement', true)
            ->assertJsonPath('data.masked_phone_number', '*******4567')
            ->assertJsonPath('data.transfer_request.status', DeviceTransferRequest::STATUS_PENDING_REVIEW)
            ->assertJsonMissingPath('data.client')
            ->assertJsonMissingPath('data.active_session.id');

        $this->assertDatabaseHas('device_transfer_requests', [
            'client_id' => $client->id,
            'active_wifi_session_id' => $activeSession->id,
            'requested_mac_address' => 'aa:bb:cc:dd:ee:ff',
            'status' => DeviceTransferRequest::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_same_device_renewal_creates_pending_extension_without_parallel_active_row(): void
    {
        $plan = $this->createPlan();
        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'last_connected_at' => now()->subDay(),
        ]);

        $activeSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'plan_id' => $plan->id,
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(50),
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $this->issuePortalToken('AA:BB:CC:DD:EE:FF'),
            'client_registration' => $this->registrationPayload(),
        ]);

        $response->assertCreated();

        $renewalSession = WifiSession::query()
            ->whereKeyNot($activeSession->id)
            ->sole();

        $this->assertSame($activeSession->id, $renewalSession->extends_session_id);
        $this->assertFalse($renewalSession->is_active);
        $this->assertSame(1, WifiSession::query()->where('client_id', $client->id)->where('is_active', true)->count());
    }

    public function test_same_account_device_reuses_existing_pending_extension_instead_of_creating_another_one(): void
    {
        $plan = $this->createPlan();
        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'last_connected_at' => now()->subDay(),
        ]);

        $device = ClientDevice::query()->create([
            'client_id' => $client->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'bound',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        $activeSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'client_device_id' => $device->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'plan_id' => $plan->id,
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(50),
        ]);

        $firstResponse = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $this->issuePortalToken('AA:BB:CC:DD:EE:FF'),
            'client_registration' => $this->registrationPayload(),
        ]);

        $firstResponse->assertCreated();

        $secondResponse = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $this->issuePortalToken('AA:BB:CC:DD:EE:FF'),
            'client_registration' => $this->registrationPayload(),
        ]);

        $secondResponse->assertCreated();

        $pendingSessions = WifiSession::query()
            ->where('extends_session_id', $activeSession->id)
            ->get();

        $this->assertCount(1, $pendingSessions);
    }

    public function test_guarded_different_mac_flow_reuses_existing_open_transfer_request(): void
    {
        $plan = $this->createPlan();
        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => '00:11:22:33:44:55',
            'last_connected_at' => now()->subDay(),
        ]);

        $device = ClientDevice::query()->create([
            'client_id' => $client->id,
            'mac_address' => '00:11:22:33:44:55',
            'status' => 'bound',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        $activeSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'client_device_id' => $device->id,
            'mac_address' => '00:11:22:33:44:55',
            'plan_id' => $plan->id,
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->addMinutes(55),
        ]);

        DeviceTransferRequest::query()->create([
            'client_id' => $client->id,
            'active_wifi_session_id' => $activeSession->id,
            'from_client_device_id' => $device->id,
            'requested_mac_address' => 'aa:bb:cc:dd:ee:ff',
            'requested_phone_number' => '09171234567',
            'status' => DeviceTransferRequest::STATUS_PENDING_REVIEW,
            'requested_at' => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $this->issuePortalToken('AA:BB:CC:DD:EE:FF'),
            'client_registration' => $this->registrationPayload(),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.transfer_request.status', DeviceTransferRequest::STATUS_PENDING_REVIEW);

        $this->assertSame(1, DeviceTransferRequest::query()->count());
    }

    public function test_guarded_different_mac_flow_does_not_create_transfer_request_without_active_entitlement(): void
    {
        $plan = $this->createPlan();
        Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => '00:11:22:33:44:55',
            'last_connected_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $this->issuePortalToken('AA:BB:CC:DD:EE:FF'),
            'client_registration' => $this->registrationPayload(),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.has_active_entitlement', false)
            ->assertJsonPath('data.transfer_request', null);

        $this->assertSame(0, DeviceTransferRequest::query()->count());
    }

    public function test_db_guard_blocks_duplicate_pending_extension_rows_for_same_active_session_and_device(): void
    {
        $plan = $this->createPlan();
        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'last_connected_at' => now()->subDay(),
        ]);

        $device = ClientDevice::query()->create([
            'client_id' => $client->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'bound',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        $activeSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'client_device_id' => $device->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'plan_id' => $plan->id,
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(50),
        ]);

        WifiSession::query()->create([
            'client_id' => $client->id,
            'client_device_id' => $device->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'plan_id' => $plan->id,
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
            'extends_session_id' => $activeSession->id,
        ]);

        $this->expectException(QueryException::class);

        WifiSession::query()->create([
            'client_id' => $client->id,
            'client_device_id' => $device->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'plan_id' => $plan->id,
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PENDING,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
            'extends_session_id' => $activeSession->id,
        ]);
    }

    public function test_legacy_client_mac_address_is_not_authoritative_when_client_devices_disagree(): void
    {
        $plan = $this->createPlan();
        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:aa:aa:aa:aa:aa',
            'last_connected_at' => now()->subDay(),
        ]);

        ClientDevice::query()->create([
            'client_id' => $client->id,
            'mac_address' => 'bb:bb:bb:bb:bb:bb',
            'status' => 'bound',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        $response = $this->postJson('/api/select-plan', [
            'plan_id' => $plan->id,
            'portal_token' => $this->issuePortalToken('AA:AA:AA:AA:AA:AA'),
            'client_registration' => $this->registrationPayload(),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.code', 'transfer_required');

        $this->assertSame(0, WifiSession::query()->count());
    }

    private function createPlan(): Plan
    {
        return Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);
    }

    private function issuePortalToken(string $macAddress): string
    {
        return app(PortalTokenService::class)->issuePortalContextToken([
            'mac_address' => $macAddress,
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'site_name' => 'Main Site',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
        ]);
    }

    private function registrationPayload(): array
    {
        return [
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ];
    }
}
