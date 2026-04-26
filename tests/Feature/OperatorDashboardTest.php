<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\BillingLedgerEntry;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Plan;
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
                ->has('recentSessions', 2)
                ->where('recentSessions.0.access_point_name', 'Claimed AP')
                ->has('recentPayments', 2)
                ->where('recentPayments.0.reference_id', 'CLAIMED123')
                ->has('accessPoints', 2)
                ->where('accessPoints.0.name', 'Claimed AP')
                ->where('accessPoints.0.active_sessions_count', 1)
                ->where('accessPoints.0.health.health_state', 'connected'));
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
