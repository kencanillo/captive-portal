<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                ->has('accessPoints', 1)
                ->has('siteSummary', 1)
                ->where('accessPoints.0.name', 'AP-01')
                ->where('siteSummary.0.name', 'North Site'));
    }
}
