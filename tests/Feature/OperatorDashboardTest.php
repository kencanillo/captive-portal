<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperatorDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_dashboard_only_shows_operator_scoped_data(): void
    {
        $this->withoutVite();

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

        $this->actingAs($user)
            ->get('/operator/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Dashboard')
                ->where('summary.sites_count', 1)
                ->where('summary.access_points_count', 1)
                ->where('summary.active_sessions_count', 1)
                ->where('summary.completed_payments_count', 1)
                ->where('summary.revenue_total', '30.00')
                ->where('webhookCapabilityVerdict', 'webhook_not_safely_supported_using_current_setup')
                ->has('recentPayments', 1)
                ->where('recentPayments.0.reference_id', 'NORTH123')
                ->where('recentAccessPoints.0.name', 'North AP')
                ->where('recentAccessPoints.0.health.health_state', 'connected'));
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
}
