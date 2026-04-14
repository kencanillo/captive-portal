<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\ControllerSetting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAccessPointTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_claim_an_access_point_and_assign_a_location(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post('/admin/access-points', [
                'name' => 'Front Gate AP',
                'serial_number' => 'SN123456789',
                'mac_address' => '11:22:33:44:55:66',
                'site_name' => 'Main Branch',
                'vendor' => 'TP-Link',
                'model' => 'EAP110',
                'ip_address' => '192.168.1.2',
                'omada_device_id' => 'device-001',
                'claim_status' => 'claimed',
                'custom_ssid' => 'KapitWiFi',
                'voucher_ssid_name' => 'KapitWiFi Voucher',
                'allow_client_pause' => true,
                'block_tethering' => true,
                'is_portal_enabled' => true,
            ])
            ->assertRedirect('/admin/access-points');

        $site = Site::query()->firstOrFail();
        $accessPoint = AccessPoint::query()->firstOrFail();

        $this->assertSame('Main Branch', $site->name);
        $this->assertSame($site->id, $accessPoint->site_id);
        $this->assertSame('claimed', $accessPoint->claim_status);
        $this->assertNotNull($accessPoint->claimed_at);
        $this->assertTrue($accessPoint->allow_client_pause);
        $this->assertTrue($accessPoint->block_tethering);
        $this->assertTrue($accessPoint->is_portal_enabled);
    }

    public function test_admin_can_sync_access_points_from_omada(): void
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
                        'siteName' => 'Main Branch',
                        'statusCategory' => 'connected',
                        'lastSeen' => 1711987200000,
                    ]],
                ],
            ]),
            'https://localhost:8043/api/v2/grid/devices/pending' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'data' => [[
                        'id' => 'device-002',
                        'name' => 'Back Gate AP',
                        'mac' => 'AA-BB-CC-DD-EE-FF',
                        'sn' => 'SN987654321',
                        'model' => 'EAP225',
                        'ip' => '192.168.1.3',
                        'siteName' => 'Main Branch',
                        'statusCategory' => 'pending',
                    ]],
                ],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'super-secret',
            'site_name' => 'Main Branch',
            'default_session_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->post('/admin/access-points/sync')
            ->assertRedirect('/admin/access-points')
            ->assertSessionHas('success', 'Omada sync finished. 2 devices scanned, 1 claimed, 1 pending, 2 created, 0 updated.');

        $this->assertDatabaseHas('sites', [
            'name' => 'Main Branch',
        ]);

        $this->assertDatabaseHas('access_points', [
            'name' => 'Front Gate AP',
            'mac_address' => '11:22:33:44:55:66',
            'claim_status' => 'claimed',
            'block_tethering' => true,
            'allow_client_pause' => true,
        ]);

        $this->assertDatabaseHas('access_points', [
            'name' => 'Back Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => 'pending',
            'block_tethering' => true,
            'allow_client_pause' => true,
        ]);
    }

    public function test_access_points_page_marks_automatic_sync_enabled_when_legacy_sync_credentials_exist(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'super-secret',
            'default_session_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->get('/admin/access-points')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AccessPoints')
                ->where('syncConfigured', true));
    }

    public function test_access_points_page_marks_automatic_sync_disabled_when_only_openapi_credentials_exist(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'api_client_id' => 'pilot-client',
            'api_client_secret' => 'pilot-secret',
            'default_session_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->get('/admin/access-points')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AccessPoints')
                ->where('syncConfigured', false));
    }
}
