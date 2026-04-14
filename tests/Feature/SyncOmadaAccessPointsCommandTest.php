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
            'api_client_id' => 'pilot-client',
            'api_client_secret' => 'pilot-secret',
            'default_session_minutes' => 60,
        ]);

        $this->artisan('omada:sync-access-points')
            ->expectsOutput('Skipping Omada AP sync because local controller username/password are missing.')
            ->assertSuccessful();
    }

    public function test_command_syncs_access_points_from_omada(): void
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

        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'super-secret',
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
