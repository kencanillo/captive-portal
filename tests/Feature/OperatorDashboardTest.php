<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\BillingLedgerEntry;
use App\Models\Client;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PayoutRequest;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use App\Services\AccessPointBillingService;
use App\Services\AccessPointHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperatorDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_dashboard_only_shows_operator_scoped_data(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();

        $user = User::factory()->create([
            'is_admin' => false,
            'email' => 'operator@example.com',
        ]);
        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => 'North WiFi',
            'contact_name' => 'North Operator',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_APPROVED,
        ]);

        $ownedSite = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => 'North Site',
            'slug' => 'north-site',
        ]);
        $otherSite = Site::query()->create([
            'name' => 'South Site',
            'slug' => 'south-site',
        ]);

        $ownedAccessPoint = AccessPoint::query()->create([
            'site_id' => $ownedSite->id,
            'name' => 'North AP',
            'mac_address' => '11:22:33:44:55:66',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'is_online' => true,
            'last_synced_at' => now(),
        ]);
        AccessPoint::query()->create([
            'site_id' => $otherSite->id,
            'name' => 'South AP',
            'mac_address' => '22:33:44:55:66:77',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'is_online' => true,
            'last_synced_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 30,
            'duration_minutes' => 60,
            'is_active' => true,
        ]);

        $ownedSession = WifiSession::query()->create([
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'plan_id' => $plan->id,
            'site_id' => $ownedSite->id,
            'access_point_id' => $ownedAccessPoint->id,
            'amount_paid' => 30,
            'payment_status' => WifiSession::STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
        ]);

        $claimedAccessPoint = AccessPoint::query()->create([
            'site_id' => null,
            'claimed_by_operator_id' => $operator->id,
            'name' => 'Claimed AP',
            'mac_address' => '33:44:55:66:77:88',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'is_online' => true,
            'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
            'health_checked_at' => now(),
            'status_source' => AccessPoint::STATUS_SOURCE_RECONCILE,
            'status_source_event_at' => now(),
            'last_synced_at' => now()->addMinute(),
        ]);

        $claimedApSession = WifiSession::query()->create([
            'mac_address' => 'cc:dd:ee:ff:00:11',
            'plan_id' => $plan->id,
            'site_id' => null,
            'access_point_id' => $claimedAccessPoint->id,
            'amount_paid' => 30,
            'payment_status' => WifiSession::STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
        ]);

        $qrPendingSession = WifiSession::query()->create([
            'mac_address' => 'dd:ee:ff:00:11:22',
            'plan_id' => $plan->id,
            'site_id' => null,
            'access_point_id' => $claimedAccessPoint->id,
            'amount_paid' => 30,
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
        ]);

        WifiSession::query()->create([
            'mac_address' => 'bb:cc:dd:ee:ff:00',
            'plan_id' => $plan->id,
            'site_id' => $otherSite->id,
            'amount_paid' => 45,
            'payment_status' => WifiSession::STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
        ]);

        Payment::query()->create([
            'wifi_session_id' => $ownedSession->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'NORTH123',
            'status' => Payment::STATUS_PAID,
            'amount' => 30,
            'currency' => 'PHP',
        ]);
        Payment::query()->create([
            'wifi_session_id' => $claimedApSession->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'CLAIMED123',
            'status' => Payment::STATUS_PAID,
            'amount' => 30,
            'currency' => 'PHP',
        ]);
        Payment::query()->create([
            'wifi_session_id' => $qrPendingSession->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'QR123',
            'status' => Payment::STATUS_AWAITING_PAYMENT,
            'amount' => 30,
            'currency' => 'PHP',
        ]);

        BillingLedgerEntry::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $ownedSite->id,
            'access_point_id' => $ownedAccessPoint->id,
            'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
            'direction' => BillingLedgerEntry::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'PHP',
            'state' => BillingLedgerEntry::STATE_POSTED,
            'billable_key' => "ap-connection-fee:{$ownedAccessPoint->id}",
            'triggered_at' => now(),
            'posted_at' => now(),
            'source' => BillingLedgerEntry::SOURCE_ADMIN_RUN,
        ]);

        $this->actingAs($user)
            ->get('/operator/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Dashboard')
                ->where('summary.sites_count', 1)
                ->where('summary.access_points_count', 2)
                ->where('summary.active_sessions_count', 2)
                ->where('summary.completed_payments_count', 2)
                ->where('summary.gross_billed_fees', '500.00')
                ->where('summary.net_payable_fees', '500.00')
                ->where('summary.available_balance', '500.00')
                ->where('summary.confidence_state', 'healthy')
                ->where('webhookCapabilityVerdict', 'webhook_not_safely_supported_using_current_setup')
                ->has('recentSessions', 3)
                ->where('recentSessions.0.access_point_name', 'Claimed AP')
                ->where('recentSessions.0.payment_status', WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT)
                ->has('recentPayments', 3)
                ->where('recentPayments.0.reference_id', 'QR123')
                ->has('accessPoints', 2)
                ->where('accessPoints.0.name', 'Claimed AP')
                ->where('accessPoints.0.active_sessions_count', 1)
                ->where('accessPoints.0.current_sessions_count', 2)
                ->where('accessPoints.0.health.health_state', 'connected'));
    }

    public function test_operator_sessions_page_lists_qr_generated_clients_with_filters(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();

        $user = User::factory()->create([
            'is_admin' => false,
            'email' => 'sessions-operator@example.com',
        ]);
        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => 'Session WiFi',
            'contact_name' => 'Session Operator',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_APPROVED,
        ]);
        $site = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => 'Session Site',
            'slug' => 'session-site',
        ]);
        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'claimed_by_operator_id' => $operator->id,
            'name' => 'Session AP',
            'mac_address' => '44:55:66:77:88:99',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
            'is_online' => true,
        ]);
        $otherSite = Site::query()->create([
            'name' => 'Other Site',
            'slug' => 'other-site',
        ]);
        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 30,
            'duration_minutes' => 60,
            'is_active' => true,
        ]);

        $session = WifiSession::query()->create([
            'mac_address' => 'aa:aa:aa:aa:aa:aa',
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'amount_paid' => 30,
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
        ]);
        Payment::query()->create([
            'wifi_session_id' => $session->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'QR-FILTERED',
            'status' => Payment::STATUS_AWAITING_PAYMENT,
            'amount' => 30,
            'currency' => 'PHP',
        ]);
        WifiSession::query()->create([
            'mac_address' => 'bb:bb:bb:bb:bb:bb',
            'plan_id' => $plan->id,
            'site_id' => $otherSite->id,
            'amount_paid' => 30,
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
        ]);
        $apMacMatchedSession = WifiSession::query()->create([
            'mac_address' => 'cc:bb:aa:99:88:77',
            'plan_id' => $plan->id,
            'site_id' => $otherSite->id,
            'access_point_id' => null,
            'ap_mac' => $accessPoint->mac_address,
            'ap_name' => 'Session AP',
            'amount_paid' => 30,
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
        ]);
        WifiSession::query()->create([
            'mac_address' => 'cc:cc:cc:cc:cc:cc',
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'amount_paid' => 30,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_PAID,
            'release_status' => WifiSession::RELEASE_STATUS_PENDING,
            'is_active' => false,
        ]);
        WifiSession::query()->create([
            'mac_address' => 'dd:dd:dd:dd:dd:dd',
            'plan_id' => $plan->id,
            'site_id' => $otherSite->id,
            'amount_paid' => 30,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_PAID,
            'release_status' => WifiSession::RELEASE_STATUS_PENDING,
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->get("/operator/sessions?status=awaiting_payment&access_point_id={$accessPoint->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Sessions')
                ->where('sessions.data.0.id', $session->id)
                ->where('sessions.data.0.access_point.name', 'Session AP')
                ->where('sessions.data.0.payment_status', WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT)
                ->has('releaseRuntime')
                ->where('releaseRuntime.outstanding_release_count', 1)
                ->has('clientHistories')
                ->has('sessions.data', 1)
                ->has('accessPoints', 1));

        $this->actingAs($user)
            ->get('/operator/sessions?client=cc:bb:aa:99:88:77')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Sessions')
                ->where('sessions.data.0.id', $apMacMatchedSession->id)
                ->where('sessions.data.0.ap_mac', $accessPoint->mac_address)
                ->has('sessions.data', 1));
    }

    public function test_operator_sales_page_lists_paid_sales_with_date_filters(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();

        $user = User::factory()->create([
            'is_admin' => false,
            'email' => 'sales-operator@example.com',
        ]);
        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => 'Sales WiFi',
            'contact_name' => 'Sales Operator',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_APPROVED,
        ]);
        $site = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => 'Sales Site',
            'slug' => 'sales-site',
        ]);
        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'claimed_by_operator_id' => $operator->id,
            'name' => 'Sales AP',
            'mac_address' => '77:88:99:aa:bb:cc',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
            'is_online' => true,
        ]);
        $otherSite = Site::query()->create([
            'name' => 'External Site',
            'slug' => 'external-site',
        ]);
        $plan = Plan::query()->create([
            'name' => 'Day Pass',
            'price' => 75,
            'duration_minutes' => 1440,
            'is_active' => true,
        ]);
        $client = Client::query()->create([
            'name' => 'Sales Client',
            'phone_number' => '09170001111',
            'pin' => '1234',
            'mac_address' => '00:11:22:33:44:55',
        ]);

        $session = WifiSession::query()->create([
            'client_id' => $client->id,
            'mac_address' => $client->mac_address,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'amount_paid' => 75,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
        ]);
        Payment::query()->create([
            'wifi_session_id' => $session->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'SALE-IN-RANGE',
            'status' => Payment::STATUS_PAID,
            'amount' => 75,
            'currency' => 'PHP',
            'paid_at' => now()->setDate(2026, 4, 20)->setTime(10, 0),
        ]);

        $outOfRangeSession = WifiSession::query()->create([
            'mac_address' => '66:55:44:33:22:11',
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'amount_paid' => 75,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
        ]);
        Payment::query()->create([
            'wifi_session_id' => $outOfRangeSession->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'SALE-OUT-OF-RANGE',
            'status' => Payment::STATUS_PAID,
            'amount' => 75,
            'currency' => 'PHP',
            'paid_at' => now()->setDate(2026, 4, 10)->setTime(10, 0),
        ]);

        $externalSession = WifiSession::query()->create([
            'mac_address' => '99:88:77:66:55:44',
            'plan_id' => $plan->id,
            'site_id' => $otherSite->id,
            'amount_paid' => 75,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
        ]);
        Payment::query()->create([
            'wifi_session_id' => $externalSession->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'SALE-EXTERNAL',
            'status' => Payment::STATUS_PAID,
            'amount' => 75,
            'currency' => 'PHP',
            'paid_at' => now()->setDate(2026, 4, 20)->setTime(10, 0),
        ]);

        $this->actingAs($user)
            ->get('/operator/sales?date_from=2026-04-15&date_to=2026-04-21')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Sales')
                ->where('summary.total_sales', '75.00')
                ->where('summary.paid_payments_count', 1)
                ->where('sales.data.0.reference_id', 'SALE-IN-RANGE')
                ->where('sales.data.0.access_point.name', 'Sales AP')
                ->has('dailySales', 1)
                ->has('accessPointSales', 1)
                ->has('sales.data', 1));
    }

    public function test_operator_payout_balance_uses_paid_sales_even_without_ap_fee_ledger(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'is_admin' => false,
            'email' => 'sales-payout-operator@example.com',
        ]);
        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => 'Sales Payout WiFi',
            'contact_name' => 'Sales Payout Operator',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_APPROVED,
        ]);
        $site = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => 'Sales Payout Site',
            'slug' => 'sales-payout-site',
        ]);
        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'claimed_by_operator_id' => $operator->id,
            'name' => 'Sales Payout AP',
            'mac_address' => '88:99:aa:bb:cc:dd',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
            'is_online' => true,
        ]);
        $plan = Plan::query()->create([
            'name' => 'Paid Pass',
            'price' => 19,
            'duration_minutes' => 60,
            'is_active' => true,
        ]);
        $session = WifiSession::query()->create([
            'mac_address' => '10:20:30:40:50:60',
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'amount_paid' => 19,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
        ]);
        Payment::query()->create([
            'wifi_session_id' => $session->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'SALE-PAYOUT',
            'status' => Payment::STATUS_PAID,
            'amount' => 19,
            'currency' => 'PHP',
            'paid_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/operator/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Dashboard')
                ->where('summary.gross_sales', '19.00')
                ->where('summary.available_balance', '19.00'));

        $this->actingAs($user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Payouts')
                ->where('summary.gross_sales', '19.00')
                ->where('summary.requestable_balance', '19.00')
                ->where('summary.confidence_state', 'healthy'));

        $this->actingAs($user)
            ->post('/operator/payouts', [
                'amount' => 19,
                'destination_type' => 'bank',
                'destination_account_name' => 'Test Operator',
                'destination_account_reference' => '1234567890',
                'destination_provider' => 'instapay',
            ])
            ->assertRedirect('/operator/payouts')
            ->assertSessionHas('success');

        $this->assertSame(1, PayoutRequest::query()->where('operator_id', $operator->id)->count());
    }

    public function test_operator_devices_page_shows_connected_and_disconnected_access_points(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();

        $user = User::factory()->create([
            'is_admin' => false,
            'email' => 'devices-operator@example.com',
        ]);
        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => 'Device WiFi',
            'contact_name' => 'Device Operator',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_APPROVED,
        ]);
        $site = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => 'Device Site',
            'slug' => 'device-site',
        ]);

        $connected = AccessPoint::query()->create([
            'site_id' => $site->id,
            'claimed_by_operator_id' => $operator->id,
            'name' => 'Connected AP',
            'mac_address' => '55:66:77:88:99:aa',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
            'is_online' => true,
        ]);
        $disconnected = AccessPoint::query()->create([
            'site_id' => $site->id,
            'claimed_by_operator_id' => $operator->id,
            'name' => 'Disconnected AP',
            'mac_address' => '66:77:88:99:aa:bb',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'health_state' => AccessPoint::HEALTH_STATE_DISCONNECTED,
            'is_online' => false,
        ]);
        $plan = Plan::query()->create([
            'name' => 'Quick Surf',
            'price' => 25,
            'duration_minutes' => 60,
            'is_active' => true,
        ]);
        WifiSession::query()->create([
            'mac_address' => 'aa:00:aa:00:aa:00',
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'access_point_id' => null,
            'ap_mac' => '77:88:99:aa:bb:cc',
            'ap_name' => 'Observed Session AP',
            'amount_paid' => 25,
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
        ]);

        $this->actingAs($user)
            ->get('/operator/devices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Devices')
                ->where('connectedDevices.0.id', $connected->id)
                ->where('connectedDevices.1.name', 'Observed Session AP')
                ->where('connectedDevices.1.current_sessions_count', 1)
                ->where('failedDevices.0.id', $disconnected->id));
    }

    public function test_operator_access_point_sync_route_returns_clear_error_without_controller_settings(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'is_admin' => false,
            'email' => 'sync-operator@example.com',
        ]);
        Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => 'Sync WiFi',
            'contact_name' => 'Sync Operator',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_APPROVED,
        ]);

        $this->actingAs($user)
            ->post('/operator/devices/sync')
            ->assertRedirect('/operator/devices')
            ->assertSessionHas('error', 'Controller settings are missing. Ask an admin to save them before syncing AP inventory.');
    }

    public function test_pending_operator_is_redirected_to_pending_approval_page(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => 'Pending WiFi',
            'contact_name' => 'Pending Operator',
            'phone_number' => '09170000000',
            'status' => Operator::STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/operator/pending');
    }

    private function noteFreshAutomation(): void
    {
        $now = now()->toIso8601String();

        Cache::put(AccessPointHealthService::SYNC_HEARTBEAT_CACHE_KEY, $now, now()->addDay());
        Cache::put(AccessPointHealthService::RECONCILE_HEARTBEAT_CACHE_KEY, $now, now()->addDay());
        Cache::put(AccessPointBillingService::POST_HEARTBEAT_CACHE_KEY, $now, now()->addDay());
    }
}
