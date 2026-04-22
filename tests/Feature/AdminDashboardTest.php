<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use App\Services\AccessPointBillingService;
use App\Services\AccessPointHealthService;
use App\Services\AutomationHealthService;
use App\Services\WifiSessionReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_access_point_and_site_summaries_on_the_admin_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $site = Site::query()->create([
            'name' => 'North Site',
            'slug' => 'north-site',
        ]);
        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'name' => 'AP-01',
            'mac_address' => '11:22:33:44:55:66',
            'is_online' => true,
            'last_seen_at' => now(),
        ]);
        $plan = Plan::query()->create([
            'name' => '2 Hours',
            'price' => 50,
            'duration_minutes' => 120,
        ]);

        WifiSession::query()->create([
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'ap_mac' => $accessPoint->mac_address,
            'ap_name' => $accessPoint->name,
            'ssid_name' => 'Guest',
            'client_ip' => '192.168.20.10',
            'amount_paid' => 50,
            'payment_status' => WifiSession::STATUS_PAID,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addMinutes(110),
        ]);

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('analytics.tracked_access_points', 1)
                ->where('analytics.sites_count', 1)
                ->where('analytics.unassigned_sessions', 0)
                ->has('automationStatus.statuses', 6)
                ->has('operationalReadiness.actions', 7)
                ->has('accessPoints', 1)
                ->has('siteSummary', 1)
                ->where('accessPoints.0.name', 'AP-01')
                ->where('siteSummary.0.name', 'North Site'));
    }

    public function test_it_surfaces_stale_runtime_automation_on_the_admin_dashboard(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create(['is_admin' => true]);
        $site = Site::query()->create([
            'name' => 'North Site',
            'slug' => 'north-site',
        ]);
        $plan = Plan::query()->create([
            'name' => '2 Hours',
            'price' => 50,
            'duration_minutes' => 120,
        ]);
        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'name' => 'AP-01',
            'mac_address' => '11:22:33:44:55:66',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'adoption_state' => AccessPoint::ADOPTION_STATE_ADOPTED,
            'claimed_by_operator_id' => null,
            'health_state' => AccessPoint::HEALTH_STATE_STALE_UNKNOWN,
            'first_confirmed_connected_at' => now()->subHour(),
            'last_synced_at' => now()->subHour(),
        ]);

        WifiSession::query()->create([
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'amount_paid' => 50,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_PAID,
            'release_status' => WifiSession::RELEASE_STATUS_PENDING,
            'is_active' => false,
        ]);

        $stale = now()->subMinutes(20)->toIso8601String();
        Cache::put(AutomationHealthService::SCHEDULER_HEARTBEAT_CACHE_KEY, $stale, now()->addDay());
        Cache::put(AutomationHealthService::QUEUE_WORKER_HEARTBEAT_CACHE_KEY, $stale, now()->addDay());
        Cache::put(AccessPointHealthService::SYNC_HEARTBEAT_CACHE_KEY, $stale, now()->addDay());
        Cache::put(AccessPointHealthService::RECONCILE_HEARTBEAT_CACHE_KEY, $stale, now()->addDay());
        Cache::put(AccessPointBillingService::POST_HEARTBEAT_CACHE_KEY, $stale, now()->addDay());
        Cache::put(WifiSessionReleaseService::JOB_HEARTBEAT_CACHE_KEY, $stale, now()->addDay());
        Cache::put(WifiSessionReleaseService::RECONCILE_HEARTBEAT_CACHE_KEY, $stale, now()->addDay());

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('automationStatus.overall_status', 'stale')
                ->where('automationStatus.overall_readiness', 'blocked')
                ->where('automationStatus.statuses.0.key', 'scheduler')
                ->where('automationStatus.statuses.0.status', 'stale')
                ->where('automationStatus.statuses.1.key', 'ap_sync')
                ->where('automationStatus.statuses.1.status', 'stale')
                ->where('automationStatus.statuses.2.key', 'ap_health_reconcile')
                ->where('automationStatus.statuses.2.status', 'stale')
                ->where('automationStatus.statuses.3.key', 'queue_worker')
                ->where('automationStatus.statuses.3.status', 'stale')
                ->where('automationStatus.statuses.4.key', 'release_reconcile')
                ->where('automationStatus.statuses.4.status', 'stale')
                ->where('operationalReadiness.overall_state', 'blocked')
                ->where('operationalReadiness.actions.0.key', 'admin_retry_release')
                ->where('operationalReadiness.actions.0.state', 'blocked')
                ->where('operationalReadiness.actions.1.key', 'billing_post')
                ->where('operationalReadiness.actions.1.state', 'warning')
                ->where('operationalReadiness.actions.3.key', 'payout_request_create')
                ->where('operationalReadiness.actions.3.state', 'blocked')
                ->where('operationalReadiness.actions.4.key', 'payout_review')
                ->where('operationalReadiness.actions.4.state', 'blocked')
                ->where('operationalReadiness.actions.5.key', 'payout_settlement')
                ->where('operationalReadiness.actions.5.state', 'blocked')
                ->where('operationalReadiness.actions.6.key', 'payout_execution')
                ->where('operationalReadiness.actions.6.state', 'blocked')
                ->where('automationStatus.incident_counts.outstanding_release_count', 1)
                ->where('automationStatus.incident_counts.stale_access_point_count', 1));
    }
}
