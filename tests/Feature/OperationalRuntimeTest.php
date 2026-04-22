<?php

namespace Tests\Feature;

use App\Jobs\ReleaseWifiAccessJob;
use App\Models\AccessPoint;
use App\Models\AccessPointClaim;
use App\Models\ControllerSetting;
use App\Models\Operator;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use App\Services\AccessPointHealthService;
use App\Services\AutomationHealthService;
use App\Services\MigrationPortabilityService;
use App\Services\OperationalReadinessService;
use App\Services\OperationalVerificationService;
use App\Services\WifiSessionReleaseService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationalRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private static int $deviceSequence = 1;

    public function test_operational_verification_reports_missing_prerequisites_honestly(): void
    {
        $result = app(OperationalVerificationService::class)->verify();

        $this->assertSame(OperationalVerificationService::STATUS_FAIL, $result['overall_status']);
        $this->assertSame('controller_connectivity', $result['checks'][0]['key']);
        $this->assertSame(OperationalVerificationService::STATUS_FAIL, $result['checks'][0]['status']);
        $this->assertSame('Controller settings are missing.', $result['checks'][0]['summary']);
    }

    public function test_operational_verification_passes_under_healthy_runtime_state(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'super-secret',
            'default_session_minutes' => 60,
        ]);

        Http::fake([
            'https://localhost:8043/api/info' => Http::response([
                'errorCode' => 0,
                'result' => [
                    'controller_name' => 'Pilot Controller',
                    'version' => '5.15.20',
                    'api_version' => 'v2',
                ],
            ]),
            'https://localhost:8043/api/v2/login' => Http::response([
                'errorCode' => 0,
                'result' => ['token' => 'abc123'],
            ]),
            'https://localhost:8043/api/v2/controller/setting' => Http::response([
                'errorCode' => 0,
                'result' => [
                    'name' => 'Pilot Controller',
                ],
            ]),
        ]);

        $fresh = now()->toIso8601String();
        Cache::put(AutomationHealthService::SCHEDULER_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());
        Cache::put(AutomationHealthService::QUEUE_WORKER_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());
        Cache::put(AccessPointHealthService::SYNC_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());
        Cache::put(AccessPointHealthService::RECONCILE_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());
        Cache::put(WifiSessionReleaseService::RECONCILE_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());

        $result = app(OperationalVerificationService::class)->verify();

        $this->assertSame(OperationalVerificationService::STATUS_PASS, $result['overall_status']);
        $this->assertSame(OperationalVerificationService::STATUS_PASS, $result['checks'][0]['status']);
        $this->assertStringContainsString('Reachable controller', $result['checks'][0]['summary']);
    }

    public function test_runtime_verification_route_is_admin_protected(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $nonAdmin = User::factory()->create(['is_admin' => false]);

        $this->post('/admin/dashboard/verify-operations')->assertRedirect('/login');

        $this->actingAs($nonAdmin)
            ->post('/admin/dashboard/verify-operations')
            ->assertForbidden();

        $this->actingAs($admin)
            ->post('/admin/dashboard/verify-operations')
            ->assertRedirect('/admin/dashboard');
    }

    public function test_runtime_verification_command_fails_when_prerequisites_are_missing(): void
    {
        $this->artisan('ops:verify-runtime')
            ->expectsOutput('Operational verification completed.')
            ->expectsOutput('Overall status: fail')
            ->expectsOutput('[FAIL] Controller connectivity: Controller settings are missing.')
            ->assertFailed();
    }

    public function test_explicit_queue_worker_heartbeat_becomes_stale_honestly(): void
    {
        $stale = now()->subMinutes(10)->toIso8601String();

        Cache::put(AutomationHealthService::SCHEDULER_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addDay());
        Cache::put(AutomationHealthService::QUEUE_WORKER_HEARTBEAT_CACHE_KEY, $stale, now()->addDay());

        $summary = app(AutomationHealthService::class)->statusSummary();

        $queueWorker = collect($summary['statuses'])->firstWhere('key', 'queue_worker');

        $this->assertSame('stale', $queueWorker['status']);
        $this->assertSame('blocked', $queueWorker['severity']);
        $this->assertTrue($queueWorker['incident_open']);
    }

    public function test_idle_system_with_fresh_worker_heartbeat_does_not_falsely_appear_dead(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'super-secret',
            'hotspot_operator_username' => 'hotspot-admin',
            'hotspot_operator_password' => 'hotspot-secret',
            'default_session_minutes' => 60,
        ]);

        $fresh = now()->toIso8601String();
        Cache::put(AutomationHealthService::SCHEDULER_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());
        Cache::put(AutomationHealthService::QUEUE_WORKER_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());
        Cache::put(AccessPointHealthService::SYNC_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());
        Cache::put(AccessPointHealthService::RECONCILE_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());
        Cache::put(WifiSessionReleaseService::RECONCILE_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());

        $summary = app(OperationalReadinessService::class)->summary();

        $releaseAction = collect($summary['actions'])->firstWhere('key', OperationalReadinessService::ACTION_ADMIN_RETRY_RELEASE);

        $this->assertSame('healthy', $summary['overall_state']);
        $this->assertSame('healthy', $releaseAction['state']);
    }

    public function test_admin_retry_release_is_blocked_when_worker_readiness_is_unhealthy(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $session = $this->createRetryableSession();

        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'super-secret',
            'hotspot_operator_username' => 'hotspot-admin',
            'hotspot_operator_password' => 'hotspot-secret',
            'default_session_minutes' => 60,
        ]);

        Cache::put(AutomationHealthService::SCHEDULER_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addDay());

        $this->actingAs($admin)
            ->post("/admin/sessions/{$session->id}/retry-release")
            ->assertRedirect('/admin/sessions')
            ->assertSessionHas('error', 'Release retry is blocked because the queue worker heartbeat is not healthy.');
    }

    public function test_admin_retry_release_still_works_when_runtime_is_healthy(): void
    {
        Bus::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $session = $this->createRetryableSession();

        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'super-secret',
            'hotspot_operator_username' => 'hotspot-admin',
            'hotspot_operator_password' => 'hotspot-secret',
            'default_session_minutes' => 60,
        ]);

        $fresh = now()->toIso8601String();
        Cache::put(AutomationHealthService::SCHEDULER_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());
        Cache::put(AutomationHealthService::QUEUE_WORKER_HEARTBEAT_CACHE_KEY, $fresh, now()->addDay());

        $this->actingAs($admin)
            ->post("/admin/sessions/{$session->id}/retry-release")
            ->assertRedirect('/admin/sessions')
            ->assertSessionHas('success', 'WiFi release retry queued.');

        Bus::assertDispatched(ReleaseWifiAccessJob::class);
    }

    public function test_admin_billing_post_is_blocked_when_runtime_readiness_is_unhealthy(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $this->createEligibleAccessPoint($operator, $site, $admin);

        Cache::put(AutomationHealthService::SCHEDULER_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addDay());

        $this->actingAs($admin)
            ->post('/admin/access-points/post-connection-fees')
            ->assertRedirect('/admin/access-points')
            ->assertSessionHas('error', 'Billing post is blocked because AP sync or AP health reconcile runtime is unhealthy.');
    }

    public function test_migration_portability_verification_flags_known_fragile_pattern(): void
    {
        $result = app(MigrationPortabilityService::class)->verifyContents([
            'fake_migration.php' => <<<'PHP'
                <?php
                DB::statement("ALTER TABLE wifi_sessions
                ADD COLUMN active_client_guard BIGINT UNSIGNED
                GENERATED ALWAYS AS (CASE WHEN is_active = 1 THEN client_id ELSE NULL END) STORED");
            PHP,
        ]);

        $this->assertSame('fail', $result['status']);
        $this->assertStringContainsString('Stored generated BIGINT guard columns are not allowed', $result['issues'][0]['summary']);
    }

    private function createRetryableSession(): WifiSession
    {
        $site = Site::query()->create([
            'name' => 'Retry Site',
            'slug' => 'retry-site',
        ]);
        $plan = Plan::query()->create([
            'name' => 'Retry Plan',
            'price' => 50,
            'duration_minutes' => 60,
            'is_active' => true,
        ]);

        return WifiSession::query()->create([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'amount_paid' => 50,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
            'release_status' => WifiSession::RELEASE_STATUS_FAILED,
            'is_active' => false,
        ]);
    }

    private function createApprovedOperator(
        string $email = 'operator@example.com',
        string $businessName = 'North WiFi',
    ): array {
        $user = User::factory()->create([
            'is_admin' => false,
            'email' => $email,
        ]);

        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => $businessName,
            'contact_name' => $businessName.' Contact',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_APPROVED,
        ]);

        $site = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => $businessName.' Site',
            'slug' => str($businessName)->slug(),
            'omada_site_id' => 'site-'.self::$deviceSequence,
        ]);

        return [$user, $operator, $site];
    }

    private function createEligibleAccessPoint(Operator $operator, Site $site, User $admin, array $overrides = []): AccessPoint
    {
        $sequence = self::$deviceSequence++;
        $serial = sprintf('SN-%06d', $sequence);
        $mac = sprintf('AA:BB:CC:%02X:%02X:%02X', ($sequence >> 16) & 0xFF, ($sequence >> 8) & 0xFF, $sequence & 0xFF);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => $serial,
            'requested_serial_number_normalized' => $serial,
            'requested_mac_address' => $mac,
            'requested_mac_address_normalized' => $mac,
            'claim_status' => AccessPointClaim::STATUS_ADOPTED,
            'claim_match_status' => AccessPointClaim::MATCH_STATUS_RESERVED,
            'claimed_at' => now()->subMinutes(20),
            'reviewed_at' => now()->subMinutes(18),
            'reviewed_by_user_id' => $admin->id,
        ]);

        return AccessPoint::query()->create(array_merge([
            'site_id' => $site->id,
            'claimed_by_operator_id' => $operator->id,
            'approved_claim_id' => $claim->id,
            'serial_number' => $serial,
            'omada_device_id' => 'device-'.$sequence,
            'name' => 'AP '.$sequence,
            'mac_address' => $mac,
            'vendor' => 'TP-Link',
            'model' => 'EAP650',
            'ip_address' => '192.168.1.'.($sequence % 200 + 10),
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'adoption_state' => AccessPoint::ADOPTION_STATE_ADOPTED,
            'claimed_at' => now()->subMinutes(20),
            'ownership_verified_at' => now()->subMinutes(10),
            'ownership_verified_by_user_id' => $admin->id,
            'billing_state' => AccessPoint::BILLING_STATE_UNBILLED,
            'last_synced_at' => now()->subMinute(),
            'is_online' => true,
            'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
            'health_checked_at' => now()->subMinute(),
            'status_source' => AccessPoint::STATUS_SOURCE_RECONCILE,
            'status_source_event_at' => now()->subMinute(),
            'last_seen_at' => now()->subMinute(),
            'first_connected_at' => now()->subMinutes(6),
            'last_connected_at' => now()->subMinute(),
            'first_confirmed_connected_at' => now()->subMinutes(5),
            'health_metadata' => [
                'confidence' => 'confirmed',
                'controller_observations' => [
                    'connected_streak' => 2,
                    'last_state' => AccessPoint::HEALTH_STATE_CONNECTED,
                    'source' => AccessPoint::STATUS_SOURCE_RECONCILE,
                ],
            ],
        ], $overrides));
    }
}
