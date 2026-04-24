<?php

namespace Tests\Feature;

use App\Models\ControllerSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncOmadaAccessPointsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_skips_cleanly_when_controller_settings_do_not_exist(): void
    {
        $this->artisan('omada:sync-access-points')
            ->expectsOutput('Skipping Omada AP sync because no controller settings exist yet.')
            ->assertSuccessful();
    }

    public function test_command_skips_cleanly_when_sync_credentials_are_missing(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'default_session_minutes' => 60,
        ]);

        $this->artisan('omada:sync-access-points')
            ->expectsOutput('Skipping Omada AP sync because OpenAPI client credentials are missing.')
            ->assertSuccessful();
    }

    public function test_command_syncs_access_points_from_omada(): void
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
                ]],
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites/site-001/grid/devices/pending?page=1&pageSize=1000' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'totalRows' => 0,
                    'data' => [],
                ],
            ]),
        ]);

        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'api_client_id' => 'pilot-client',
            'api_client_secret' => 'pilot-secret',
            'site_name' => 'Main Branch',
            'default_session_minutes' => 60,
        ]);

        $this->artisan('omada:sync-access-points')
            ->expectsOutput('Omada AP sync finished. 1 devices scanned, 1 claimed, 0 pending, 1 created, 0 updated.')
            ->assertSuccessful();

        $this->assertDatabaseHas('access_points', [
            'name' => 'Front Gate AP',
            'mac_address' => '11:22:33:44:55:66',
            'claim_status' => 'claimed',
        ]);
    }
}
