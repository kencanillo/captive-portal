<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\ControllerSetting;
use App\Models\Operator;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccessPointHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_second_consistent_sync_sets_first_confirmed_connected_once(): void
    {
        Carbon::setTestNow('2026-04-21 10:00:00');

        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'super-secret',
            'default_session_minutes' => 60,
        ]);

        $this->fakeSyncPayload('connected', 1711987200000);

        $this->artisan('omada:sync-access-points')->assertSuccessful();

        $accessPoint = AccessPoint::query()->firstOrFail();
        $this->assertSame(AccessPoint::HEALTH_STATE_CONNECTED, $accessPoint->health_state);
        $this->assertNull($accessPoint->first_confirmed_connected_at);

        Carbon::setTestNow(now()->addMinute());
        $this->fakeSyncPayload('connected', 1711987260000);

        $this->artisan('omada:sync-access-points')->assertSuccessful();

        $accessPoint->refresh();
        $this->assertNotNull($accessPoint->first_confirmed_connected_at);
        $confirmedAt = $accessPoint->first_confirmed_connected_at?->toDateTimeString();

        Carbon::setTestNow(now()->addMinute());
        $this->fakeSyncPayload('connected', 1711987320000);

        $this->artisan('omada:sync-access-points')->assertSuccessful();

        $accessPoint->refresh();
        $this->assertSame($confirmedAt, $accessPoint->first_confirmed_connected_at?->toDateTimeString());
    }

    public function test_reconcile_command_marks_stale_snapshots_unknown(): void
    {
        $accessPoint = AccessPoint::query()->create([
            'name' => 'Front Gate AP',
            'mac_address' => '11:22:33:44:55:66',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
            'health_checked_at' => now()->subMinutes(10),
            'status_source' => AccessPoint::STATUS_SOURCE_SYNC,
            'status_source_event_at' => now()->subMinutes(10),
            'is_online' => true,
            'last_seen_at' => now()->subMinutes(10),
            'last_synced_at' => now()->subMinutes(10),
        ]);

        $this->artisan('omada:reconcile-access-point-health')
            ->expectsOutput('AP health reconciliation finished. 1 access points moved to stale_unknown.')
            ->assertSuccessful();

        $accessPoint->refresh();

        $this->assertSame(AccessPoint::HEALTH_STATE_STALE_UNKNOWN, $accessPoint->health_state);
        $this->assertFalse($accessPoint->is_online);
        $this->assertSame(AccessPoint::STATUS_SOURCE_RECONCILE, $accessPoint->status_source);
        $this->assertNotNull($accessPoint->last_health_mismatch_at);
    }

    public function test_health_drift_is_visible_without_corrupting_ownership_metadata(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => 'North WiFi',
            'contact_name' => 'North Operator',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_APPROVED,
        ]);
        $site = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => 'North Site',
            'slug' => 'north-site',
        ]);

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'claimed_by_operator_id' => $operator->id,
            'name' => 'North AP',
            'omada_device_id' => 'device-001',
            'mac_address' => '11:22:33:44:55:66',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'adoption_state' => AccessPoint::ADOPTION_STATE_ADOPTED,
            'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
            'health_checked_at' => now()->subMinute(),
            'status_source' => AccessPoint::STATUS_SOURCE_SYNC,
            'status_source_event_at' => now()->subMinute(),
            'first_confirmed_connected_at' => now()->subDay(),
            'is_online' => true,
            'last_seen_at' => now()->subMinute(),
            'last_synced_at' => now()->subMinute(),
        ]);

        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'super-secret',
            'default_session_minutes' => 60,
        ]);

        $this->fakeSyncPayload('disconnected', 1711987380000, 'North Site');

        $this->artisan('omada:sync-access-points')->assertSuccessful();

        $accessPoint->refresh();

        $this->assertSame(AccessPoint::HEALTH_STATE_DISCONNECTED, $accessPoint->health_state);
        $this->assertNotNull($accessPoint->last_health_mismatch_at);
        $this->assertSame($operator->id, $accessPoint->claimed_by_operator_id);
        $this->assertSame(AccessPoint::ADOPTION_STATE_ADOPTED, $accessPoint->adoption_state);
        $this->assertSame('connected', data_get($accessPoint->health_metadata, 'last_mismatch.previous_state'));
        $this->assertSame('disconnected', data_get($accessPoint->health_metadata, 'last_mismatch.current_state'));
    }

    private function fakeSyncPayload(string $statusCategory, int $lastSeenMillis, string $siteName = 'Main Branch'): void
    {
        Http::fake([
            'https://localhost:8043/api/v2/login' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => ['token' => 'abc123'],
            ]),
            'https://localhost:8043/api/v2/grid/devices/adopted' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'data' => [[
                        'id' => 'device-001',
                        'name' => 'Front Gate AP',
                        'mac' => '11-22-33-44-55-66',
                        'sn' => 'SN123456789',
                        'model' => 'EAP110',
                        'ip' => '192.168.1.2',
                        'siteName' => $siteName,
                        'statusCategory' => $statusCategory,
                        'lastSeen' => $lastSeenMillis,
                    ]],
                ],
            ]),
            'https://localhost:8043/api/v2/grid/devices/pending' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'data' => [],
                ],
            ]),
        ]);
    }
}
