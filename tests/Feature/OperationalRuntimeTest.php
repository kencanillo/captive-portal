<?php

namespace Tests\Feature;

use App\Models\ControllerSetting;
use App\Models\User;
use App\Services\AccessPointHealthService;
use App\Services\AutomationHealthService;
use App\Services\OperationalVerificationService;
use App\Services\WifiSessionReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationalRuntimeTest extends TestCase
{
    use RefreshDatabase;

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
}
