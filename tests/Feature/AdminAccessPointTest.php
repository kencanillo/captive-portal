<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\ControllerSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminAccessPointTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_access_point_creation_endpoint_is_not_available_anymore(): void
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
                'custom_ssid' => 'KennFi Lab',
                'voucher_ssid_name' => 'KennFi Lab Voucher',
                'allow_client_pause' => true,
                'block_tethering' => true,
                'is_portal_enabled' => true,
            ])
            ->assertStatus(405);
    }

    public function test_admin_can_sync_access_points_from_omada(): void
    {
        Http::fake([
            'https://localhost:8043/api/info' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'controllerVer' => '6.1.0.19',
                    'apiVer' => '3',
                    'omadacId' => 'controller-id',
                ],
            ]),
            'https://localhost:8043/openapi/authorize/token?grant_type=client_credentials' => Http::response([
                'errorCode' => 0,
                'msg' => 'Open API Get Access Token successfully.',
                'result' => [
                    'accessToken' => 'access-token',
                    'expiresIn' => 7200,
                ],
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites?page=1&pageSize=1000' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'data' => [[
                        'siteId' => 'site-001',
                        'name' => 'Main Branch',
                    ]],
                ],
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites/site-001/devices/all' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [[
                    'id' => 'device-001',
                    'name' => 'Front Gate AP',
                    'mac' => '11-22-33-44-55-66',
                    'sn' => 'SN123456789',
                    'type' => 'ap',
                    'model' => 'EAP110',
                    'ip' => '192.168.1.2',
                    'status' => 1,
                    'lastSeen' => 1711987200000,
                ]],
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites/site-001/grid/devices/pending?page=1&pageSize=1000' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'totalRows' => 1,
                    'data' => [[
                        'id' => 'device-002',
                        'name' => 'Back Gate AP',
                        'mac' => 'AA-BB-CC-DD-EE-FF',
                        'sn' => 'SN987654321',
                        'type' => 'ap',
                        'model' => 'EAP225',
                        'ip' => '192.168.1.3',
                        'status' => 2,
                    ]],
                ],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'api_client_id' => 'pilot-client',
            'api_client_secret' => 'pilot-secret',
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
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'claim_status' => 'pending',
            'block_tethering' => true,
            'allow_client_pause' => true,
        ]);
    }

    public function test_access_points_page_keeps_automatic_sync_disabled_when_only_legacy_sync_credentials_exist(): void
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
                ->where('syncConfigured', false)
                ->where('webhookCapabilityVerdict', 'webhook_not_safely_supported_using_current_setup')
                ->has('healthRuntime'));
    }

    public function test_access_points_page_marks_automatic_sync_enabled_when_only_openapi_credentials_exist(): void
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
                ->where('syncConfigured', true)
                ->where('webhookCapabilityVerdict', 'webhook_not_safely_supported_using_current_setup'));
    }

    public function test_admin_can_sync_access_points_from_omada_using_openapi_credentials_without_legacy_login(): void
    {
        Http::fake([
            'https://localhost:8043/api/info' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'controllerVer' => '6.1.0.19',
                    'apiVer' => '3',
                    'omadacId' => 'controller-id',
                ],
            ]),
            'https://localhost:8043/openapi/authorize/token?grant_type=client_credentials' => Http::response([
                'errorCode' => 0,
                'msg' => 'Open API Get Access Token successfully.',
                'result' => [
                    'accessToken' => 'access-token',
                    'expiresIn' => 7200,
                ],
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites?page=1&pageSize=1000' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'data' => [[
                        'siteId' => 'site-001',
                        'name' => 'Main Branch',
                    ]],
                ],
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites/site-001/devices/all' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [[
                    'mac' => '11-22-33-44-55-66',
                    'name' => 'Front Gate AP',
                    'type' => 'ap',
                    'model' => 'EAP110',
                    'ip' => '192.168.1.2',
                    'sn' => 'SN123456789',
                    'status' => 1,
                    'lastSeen' => 1711987200000,
                ]],
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites/site-001/grid/devices/pending?page=1&pageSize=1000' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'totalRows' => 1,
                    'data' => [[
                        'mac' => 'AA-BB-CC-DD-EE-FF',
                        'name' => 'Back Gate AP',
                        'type' => 'ap',
                        'model' => 'EAP225',
                        'ip' => '192.168.1.3',
                        'sn' => 'SN987654321',
                        'status' => 2,
                    ]],
                ],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'wrong-local-user',
            'password' => 'wrong-local-password',
            'api_client_id' => 'pilot-client',
            'api_client_secret' => 'pilot-secret',
            'default_session_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->post('/admin/access-points/sync')
            ->assertRedirect('/admin/access-points')
            ->assertSessionHas('success', 'Omada sync finished. 2 devices scanned, 1 claimed, 1 pending, 2 created, 0 updated.');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v2/login'));

        $this->assertDatabaseHas('access_points', [
            'name' => 'Front Gate AP',
            'mac_address' => '11:22:33:44:55:66',
            'claim_status' => 'claimed',
        ]);

        $this->assertDatabaseHas('access_points', [
            'name' => 'Back Gate AP',
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'claim_status' => 'pending',
        ]);
    }

    public function test_sync_uses_openapi_site_context_even_when_device_payload_is_missing_site_details(): void
    {
        Http::fake([
            'https://localhost:8043/api/info' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'controllerVer' => '6.1.0.19',
                    'apiVer' => '3',
                    'omadacId' => 'controller-id',
                ],
            ]),
            'https://localhost:8043/openapi/authorize/token?grant_type=client_credentials' => Http::response([
                'errorCode' => 0,
                'msg' => 'Open API Get Access Token successfully.',
                'result' => [
                    'accessToken' => 'access-token',
                    'expiresIn' => 7200,
                ],
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites?page=1&pageSize=1000' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'data' => [[
                        'siteId' => 'site-001',
                        'name' => 'Main Branch',
                    ]],
                ],
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites/site-001/devices/all' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [],
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites/site-001/grid/devices/pending?page=1&pageSize=1000' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'totalRows' => 1,
                    'data' => [[
                        'id' => 'device-002',
                        'name' => 'Back Gate AP',
                        'mac' => 'AA-BB-CC-DD-EE-FF',
                        'sn' => 'SN987654321',
                        'type' => 'ap',
                        'model' => 'EAP225',
                        'ip' => '192.168.1.3',
                        'status' => 2,
                    ]],
                ],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'api_client_id' => 'pilot-client',
            'api_client_secret' => 'pilot-secret',
            'site_name' => 'Main Branch',
            'default_session_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->post('/admin/access-points/sync')
            ->assertRedirect('/admin/access-points');

        $this->assertDatabaseHas('access_points', [
            'name' => 'Back Gate AP',
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'site_id' => 1,
        ]);

        $this->assertDatabaseHas('sites', [
            'name' => 'Main Branch',
        ]);
    }
}
