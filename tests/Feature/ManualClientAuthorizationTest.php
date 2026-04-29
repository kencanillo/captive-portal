<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use App\Services\OmadaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ManualClientAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_authorize_any_client_with_plan(): void
    {
        [$site, $accessPoint] = $this->createSiteAndAccessPoint();
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = Plan::query()->create(['name' => '1 Hour', 'price' => 50, 'duration_minutes' => 60, 'is_active' => true]);
        $this->seedControllerSettings();
        $this->mockOmadaSuccess();

        $response = $this->actingAs($admin)->post(route('manual-authorizations.store'), [
            'client_name' => 'Admin Walk-in',
            'phone' => '09170000000',
            'mac_address' => 'aa-bb-cc-dd-ee-ff',
            'plan_id' => $plan->id,
            'manual_payment_mode' => 'admin_approved',
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'ap_mac' => $accessPoint->mac_address,
            'ssid_name' => 'Guest SSID',
            'radio_id' => 1,
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('wifi_sessions', [
            'source' => 'manual_admin',
            'operator_id' => null,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
        ]);
        $this->assertDatabaseHas('payments', [
            'provider' => Payment::PROVIDER_MANUAL,
            'status' => Payment::STATUS_WAIVED,
        ]);
    }

    public function test_operator_can_authorize_client_under_assigned_site(): void
    {
        [$operatorUser, $operator, $site, $accessPoint] = $this->createApprovedOperatorContext();
        $plan = Plan::query()->create(['name' => '30 Minutes', 'price' => 20, 'duration_minutes' => 30, 'is_active' => true]);
        $this->seedControllerSettings();
        $this->mockOmadaSuccess();

        $response = $this->actingAs($operatorUser)->post(route('manual-authorizations.store'), [
            'client_name' => 'Operator Client',
            'phone' => '09171111111',
            'mac_address' => 'aa:bb:cc:dd:ee:11',
            'plan_id' => $plan->id,
            'manual_payment_mode' => 'manually_paid',
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'ap_mac' => $accessPoint->mac_address,
            'ssid_name' => 'Guest SSID',
            'radio_id' => 1,
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('wifi_sessions', [
            'source' => 'manual_operator',
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
        ]);
        $this->assertDatabaseHas('payments', [
            'provider' => Payment::PROVIDER_MANUAL,
            'status' => Payment::STATUS_CASH_COLLECTED,
            'operator_id' => $operator->id,
        ]);
    }

    public function test_manual_authorization_can_upgrade_existing_qr_session_instead_of_creating_new_one(): void
    {
        [$operatorUser, $operator, $site, $accessPoint] = $this->createApprovedOperatorContext();
        $plan = Plan::query()->create(['name' => '45 Minutes', 'price' => 30, 'duration_minutes' => 45, 'is_active' => true]);
        $this->seedControllerSettings();
        $this->mockOmadaSuccess();

        $client = Client::query()->create([
            'name' => 'QR Client',
            'phone_number' => '09179999999',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:aa',
            'last_connected_at' => now(),
        ]);

        $session = WifiSession::query()->create([
            'client_id' => $client->id,
            'mac_address' => $client->mac_address,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'ap_mac' => $accessPoint->mac_address,
            'ap_name' => $accessPoint->name,
            'ssid_name' => 'Guest SSID',
            'radio_id' => 1,
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
        ]);

        Payment::query()->create([
            'wifi_session_id' => $session->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'reference_id' => 'pi_pending',
            'status' => Payment::STATUS_AWAITING_PAYMENT,
            'amount' => $plan->price,
            'currency' => 'PHP',
        ]);

        $this->actingAs($operatorUser)->post(route('manual-authorizations.store'), [
            'wifi_session_id' => $session->id,
            'plan_id' => $plan->id,
            'manual_payment_mode' => 'manually_paid',
            'note' => 'Paid in cash at store.',
        ])->assertSessionHas('success');

        $this->assertSame(1, WifiSession::query()->count());
        $this->assertSame(WifiSession::SESSION_STATUS_ACTIVE, $session->fresh()->session_status);
        $this->assertDatabaseHas('payments', [
            'wifi_session_id' => $session->id,
            'provider' => Payment::PROVIDER_MANUAL,
            'status' => Payment::STATUS_CASH_COLLECTED,
            'operator_id' => $operator->id,
        ]);
        $this->assertDatabaseHas('payments', [
            'wifi_session_id' => $session->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'status' => Payment::STATUS_CANCELED,
        ]);
    }

    public function test_operator_cannot_authorize_client_from_other_site_and_non_operator_blocked(): void
    {
        [$operatorUser] = $this->createApprovedOperatorContext();
        [$otherSite, $otherAccessPoint] = $this->createSiteAndAccessPoint();
        $plan = Plan::query()->create(['name' => '30 Minutes', 'price' => 20, 'duration_minutes' => 30, 'is_active' => true]);
        $this->seedControllerSettings();
        $this->mockOmadaSuccess();

        $this->actingAs($operatorUser)->post(route('manual-authorizations.store'), [
            'client_name' => 'Blocked Client',
            'phone' => '09172222222',
            'mac_address' => 'aa:bb:cc:dd:ee:12',
            'plan_id' => $plan->id,
            'manual_payment_mode' => 'manually_paid',
            'site_id' => $otherSite->id,
            'access_point_id' => $otherAccessPoint->id,
            'ap_mac' => $otherAccessPoint->mac_address,
            'ssid_name' => 'Other SSID',
            'radio_id' => 1,
        ])->assertSessionHas('error', 'You can only authorize clients connected to your assigned site or access point.');

        $regularUser = User::factory()->create(['is_admin' => false]);
        $this->actingAs($regularUser)->post(route('manual-authorizations.store'), [
            'client_name' => 'Regular User',
            'phone' => '09173333333',
            'mac_address' => 'aa:bb:cc:dd:ee:13',
            'plan_id' => $plan->id,
            'manual_payment_mode' => 'manually_paid',
            'site_id' => $otherSite->id,
            'access_point_id' => $otherAccessPoint->id,
            'ap_mac' => $otherAccessPoint->mac_address,
            'ssid_name' => 'Other SSID',
            'radio_id' => 1,
        ])->assertSessionHas('error', 'You can only authorize clients connected to your assigned site or access point.');
    }

    public function test_invalid_or_inactive_plan_is_rejected_and_duplicate_active_session_is_expired_first(): void
    {
        [$operatorUser, $operator, $site, $accessPoint] = $this->createApprovedOperatorContext();
        $inactive = Plan::query()->create(['name' => 'Old Plan', 'price' => 20, 'duration_minutes' => 30, 'is_active' => false]);
        $active = Plan::query()->create(['name' => 'New Plan', 'price' => 20, 'duration_minutes' => 30, 'is_active' => true]);
        $this->seedControllerSettings();
        $this->mockOmadaSuccess();

        $this->actingAs($operatorUser)->post(route('manual-authorizations.store'), [
            'client_name' => 'Inactive Plan',
            'phone' => '09174444444',
            'mac_address' => 'aa:bb:cc:dd:ee:14',
            'plan_id' => $inactive->id,
            'manual_payment_mode' => 'manually_paid',
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'ap_mac' => $accessPoint->mac_address,
            'ssid_name' => 'Guest SSID',
            'radio_id' => 1,
        ])->assertSessionHas('error', 'The selected plan is invalid or inactive.');

        $client = Client::query()->create([
            'name' => 'Existing',
            'phone_number' => '09175555555',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:15',
            'last_connected_at' => now(),
        ]);
        $existingSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $active->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'mac_address' => 'aa:bb:cc:dd:ee:15',
            'ap_mac' => $accessPoint->mac_address,
            'ap_name' => $accessPoint->name,
            'ssid_name' => 'Guest SSID',
            'radio_id' => 1,
            'amount_paid' => 20,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->addMinutes(25),
            'is_active' => true,
            'source' => 'manual_operator',
            'operator_id' => $operator->id,
        ]);

        $this->actingAs($operatorUser)->post(route('manual-authorizations.store'), [
            'client_name' => 'Replacement',
            'phone' => '09175555555',
            'mac_address' => 'aa:bb:cc:dd:ee:15',
            'plan_id' => $active->id,
            'manual_payment_mode' => 'manually_paid',
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'ap_mac' => $accessPoint->mac_address,
            'ssid_name' => 'Guest SSID',
            'radio_id' => 1,
        ])->assertSessionHas('success');

        $this->assertFalse($existingSession->fresh()->is_active);
    }

    public function test_omada_failure_marks_session_failed_and_retry_uses_remaining_seconds(): void
    {
        [$admin, $site, $accessPoint, $plan] = $this->createAdminContextWithPlan();
        $this->seedControllerSettings();

        $failingOmada = Mockery::mock(OmadaService::class);
        $failingOmada->shouldReceive('authorizeClientForManualSession')->once()->andThrow(new \RuntimeException('controller timeout'));
        $this->app->instance(OmadaService::class, $failingOmada);

        $this->actingAs($admin)->post(route('manual-authorizations.store'), [
            'client_name' => 'Failure Client',
            'phone' => '09176666666',
            'mac_address' => 'aa:bb:cc:dd:ee:16',
            'plan_id' => $plan->id,
            'manual_payment_mode' => 'manually_paid',
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'ap_mac' => $accessPoint->mac_address,
            'ssid_name' => 'Guest SSID',
            'radio_id' => 1,
        ])->assertSessionHas('error', 'Session was created but Omada authorization failed. Please retry.');

        $failedSession = WifiSession::query()->latest('id')->firstOrFail();
        $this->assertSame(WifiSession::SESSION_STATUS_RELEASE_FAILED, $failedSession->session_status);

        $retryOmada = Mockery::mock(OmadaService::class);
        $retryOmada->shouldReceive('authorizeClientForManualSession')
            ->once()
            ->withArgs(function ($settings, WifiSession $session) use ($failedSession): bool {
                return $session->id === $failedSession->id
                    && now()->diffInSeconds($session->end_time, false) > 0;
            })
            ->andReturn(['errorCode' => 0]);
        $this->app->instance(OmadaService::class, $retryOmada);

        $this->actingAs($admin)->post(route('manual-authorizations.retry', $failedSession))
            ->assertSessionHas('success');
    }

    public function test_waived_sessions_not_counted_in_revenue(): void
    {
        [$site, $accessPoint] = $this->createSiteAndAccessPoint();
        $plan = Plan::query()->create(['name' => '1 Hour', 'price' => 50, 'duration_minutes' => 60, 'is_active' => true]);

        $paidClient = Client::query()->create([
            'name' => 'Paid Client',
            'phone_number' => '09179999999',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:19',
            'last_connected_at' => now(),
        ]);

        $paidSession = WifiSession::query()->create([
            'client_id' => $paidClient->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'mac_address' => 'aa:bb:cc:dd:ee:19',
            'ap_mac' => $accessPoint->mac_address,
            'ap_name' => $accessPoint->name,
            'ssid_name' => 'Guest SSID',
            'radio_id' => 1,
            'amount_paid' => 50,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'start_time' => now()->subMinute(),
            'end_time' => now()->addMinutes(59),
            'is_active' => true,
        ]);

        Payment::query()->create([
            'wifi_session_id' => $paidSession->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'reference_id' => 'pi_paid',
            'status' => Payment::STATUS_PAID,
            'amount' => 50,
            'currency' => 'PHP',
        ]);

        $waivedClient = Client::query()->create([
            'name' => 'Waived Client',
            'phone_number' => '09178888888',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:20',
            'last_connected_at' => now(),
        ]);

        $waivedSession = WifiSession::query()->create([
            'client_id' => $waivedClient->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'mac_address' => 'aa:bb:cc:dd:ee:20',
            'ap_mac' => $accessPoint->mac_address,
            'ap_name' => $accessPoint->name,
            'ssid_name' => 'Guest SSID',
            'radio_id' => 1,
            'amount_paid' => 50,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'start_time' => now()->subMinute(),
            'end_time' => now()->addMinutes(59),
            'is_active' => true,
        ]);

        Payment::query()->create([
            'wifi_session_id' => $waivedSession->id,
            'provider' => Payment::PROVIDER_MANUAL,
            'reference_id' => 'manual-waived',
            'status' => Payment::STATUS_WAIVED,
            'amount' => 50,
            'currency' => 'PHP',
        ]);

        $revenue = WifiSession::query()
            ->where('payment_status', WifiSession::STATUS_PAID)
            ->whereHas('latestPayment', fn ($query) => $query->where('status', '!=', Payment::STATUS_WAIVED))
            ->sum('amount_paid');

        $this->assertEquals(50.0, $revenue);
    }

    public function test_scheduler_only_deauthorizes_expired_manual_sessions(): void
    {
        $site = Site::query()->create(['name' => 'Main', 'slug' => 'main']);
        $plan = Plan::query()->create(['name' => 'Plan', 'price' => 10, 'duration_minutes' => 10, 'is_active' => true]);
        $this->seedControllerSettings();
        $activeClient = Client::query()->create([
            'name' => 'Client Active',
            'phone_number' => '09177777777',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:17',
            'last_connected_at' => now(),
        ]);

        $activeManual = WifiSession::query()->create([
            'client_id' => $activeClient->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'mac_address' => 'aa:bb:cc:dd:ee:17',
            'ap_mac' => '11:22:33:44:55:77',
            'ap_name' => 'AP',
            'ssid_name' => 'SSID',
            'radio_id' => 1,
            'amount_paid' => 10,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'start_time' => now()->subMinute(),
            'end_time' => now()->addMinutes(5),
            'is_active' => true,
            'source' => 'manual_operator',
        ]);

        $expiredClient = Client::query()->create([
            'name' => 'Client Expired',
            'phone_number' => '09177777778',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:18',
            'last_connected_at' => now(),
        ]);

        $expiredManual = WifiSession::query()->create([
            'client_id' => $expiredClient->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'mac_address' => 'aa:bb:cc:dd:ee:18',
            'ap_mac' => '11:22:33:44:55:78',
            'ap_name' => 'AP2',
            'ssid_name' => 'SSID',
            'radio_id' => 1,
            'amount_paid' => 10,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'start_time' => now()->subMinutes(20),
            'end_time' => now()->subMinute(),
            'is_active' => true,
            'source' => 'manual_operator',
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('deauthorizeClient')->once()->andReturn(['errorCode' => 0]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->artisan('wifi:expire-sessions')->assertSuccessful();

        $this->assertTrue($activeManual->fresh()->is_active);
        $this->assertFalse($expiredManual->fresh()->is_active);
    }

    private function createApprovedOperatorContext(): array
    {
        $operatorUser = User::factory()->create(['is_admin' => false]);
        $operator = Operator::query()->create([
            'user_id' => $operatorUser->id,
            'business_name' => 'Ops',
            'contact_name' => 'Ops',
            'phone_number' => '09178888888',
            'status' => Operator::STATUS_APPROVED,
        ]);
        $site = Site::query()->create(['name' => 'Operator Site', 'slug' => 'operator-site', 'operator_id' => $operator->id]);
        $accessPoint = AccessPoint::query()->create(['site_id' => $site->id, 'name' => 'AP-1', 'mac_address' => '11:22:33:44:55:66']);

        return [$operatorUser, $operator, $site, $accessPoint];
    }

    private function createAdminContextWithPlan(): array
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$site, $accessPoint] = $this->createSiteAndAccessPoint();
        $plan = Plan::query()->create(['name' => '1 Hour', 'price' => 50, 'duration_minutes' => 60, 'is_active' => true]);

        return [$admin, $site, $accessPoint, $plan];
    }

    private function createSiteAndAccessPoint(): array
    {
        $site = Site::query()->create(['name' => 'Main Site', 'slug' => 'main-site']);
        $accessPoint = AccessPoint::query()->create(['site_id' => $site->id, 'name' => 'Main AP', 'mac_address' => '11:22:33:44:55:60']);

        return [$site, $accessPoint];
    }

    private function seedControllerSettings(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]);
    }

    private function mockOmadaSuccess(): void
    {
        $omada = Mockery::mock(OmadaService::class);
        $omada->shouldReceive('authorizeClientForManualSession')->andReturn(['errorCode' => 0]);
        $this->app->instance(OmadaService::class, $omada);
    }
}
