<?php

namespace Tests\Feature;

use App\Models\ControllerSetting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminControllerSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_update_controller_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get('/admin/controller')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ControllerSettings')
                ->where('controllerSettings.controller_name', 'Primary Omada Controller'));

        $this->actingAs($admin)
            ->put('/admin/controller', [
                'controller_name' => 'Oracle Pilot Controller',
                'base_url' => 'https://controller.example.com',
                'site_identifier' => 'pilot-site',
                'site_name' => 'Pilot Site',
                'portal_base_url' => 'https://portal.example.com',
                'username' => 'admin',
                'password' => 'super-secret',
                'api_client_id' => 'pilot-client',
                'api_client_secret' => 'pilot-secret',
                'default_session_minutes' => 90,
            ])
            ->assertRedirect('/admin/controller');

        $settings = ControllerSetting::query()->firstOrFail();

        $this->assertSame('Oracle Pilot Controller', $settings->controller_name);
        $this->assertSame('https://controller.example.com', $settings->base_url);
        $this->assertSame('pilot-site', $settings->site_identifier);
        $this->assertSame('Pilot Site', $settings->site_name);
        $this->assertSame('https://portal.example.com', $settings->portal_base_url);
        $this->assertSame('admin', $settings->username);
        $this->assertSame('super-secret', $settings->password);
        $this->assertSame('pilot-client', $settings->api_client_id);
        $this->assertSame('pilot-secret', $settings->api_client_secret);
        $this->assertSame(90, $settings->default_session_minutes);
    }

    public function test_blank_sensitive_fields_do_not_wipe_saved_controller_credentials(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'site_identifier' => 'default',
            'site_name' => 'Main Branch',
            'portal_base_url' => 'https://portal.example.com',
            'username' => 'kencanillo',
            'password' => 'super-secret',
            'api_client_id' => 'pilot-client',
            'api_client_secret' => 'pilot-secret',
            'default_session_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->put('/admin/controller', [
                'controller_name' => 'Pilot Controller',
                'base_url' => 'https://localhost:8043',
                'site_identifier' => 'default',
                'site_name' => 'Main Branch',
                'portal_base_url' => 'https://portal.example.com',
                'username' => '',
                'password' => '',
                'api_client_id' => '',
                'api_client_secret' => '',
                'default_session_minutes' => 60,
            ])
            ->assertRedirect('/admin/controller');

        $settings = ControllerSetting::query()->firstOrFail();

        $this->assertSame('kencanillo', $settings->username);
        $this->assertSame('super-secret', $settings->password);
        $this->assertSame('pilot-client', $settings->api_client_id);
        $this->assertSame('pilot-secret', $settings->api_client_secret);
    }

    public function test_admin_can_save_and_test_the_current_controller_connection(): void
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
                    'accessToken' => 'AT-abc123',
                    'expiresIn' => 7200,
                ],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post('/admin/controller/test-connection')
            ->assertSessionHasErrors([
                'controller_name',
                'base_url',
                'default_session_minutes',
            ]);

        $this->actingAs($admin)
            ->post('/admin/controller/test-connection', [
                'controller_name' => 'Pilot Controller',
                'base_url' => 'https://localhost:8043',
                'site_identifier' => null,
                'site_name' => 'Main Branch',
                'portal_base_url' => 'https://portal.example.com',
                'username' => 'admin',
                'password' => 'super-secret',
                'api_client_id' => 'pilot-client',
                'api_client_secret' => 'pilot-secret',
                'default_session_minutes' => 60,
            ])
            ->assertRedirect('/admin/controller')
            ->assertSessionHas('success', 'Connected to Pilot Controller v6.1.0.19. Settings were saved and verified.');

        $settings = ControllerSetting::query()->firstOrFail();

        $this->assertSame('admin', $settings->username);
        $this->assertSame('pilot-client', $settings->api_client_id);
        $this->assertSame('Main Branch', $settings->site_name);
        $this->assertNotNull($settings->last_tested_at);
    }

    public function test_admin_can_save_and_test_connection_with_openapi_client_credentials(): void
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
                    'accessToken' => 'AT-abc123',
                    'expiresIn' => 7200,
                ],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post('/admin/controller/test-connection', [
                'controller_name' => 'Pilot Controller',
                'base_url' => 'https://localhost:8043',
                'site_identifier' => null,
                'site_name' => 'Main Branch',
                'portal_base_url' => 'https://portal.example.com',
                'username' => 'wrong-local-user',
                'password' => 'wrong-local-password',
                'api_client_id' => 'pilot-client',
                'api_client_secret' => 'pilot-secret',
                'default_session_minutes' => 60,
            ])
            ->assertRedirect('/admin/controller')
            ->assertSessionHas('success', 'Connected to Pilot Controller v6.1.0.19. Settings were saved and verified.');

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/openapi/authorize/token?grant_type=client_credentials')) {
                return false;
            }

            return $request['omadacId'] === 'controller-id'
                && $request['client_id'] === 'pilot-client'
                && $request['client_secret'] === 'pilot-secret';
        });

        $settings = ControllerSetting::query()->firstOrFail();

        $this->assertSame('wrong-local-user', $settings->username);
        $this->assertSame('pilot-client', $settings->api_client_id);
        $this->assertSame('pilot-secret', $settings->api_client_secret);
        $this->assertNotNull($settings->last_tested_at);
    }

    public function test_admin_can_sync_sites_from_omada_controller(): void
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
                    'accessToken' => 'AT-abc123',
                    'expiresIn' => 7200,
                ],
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites?page=1&pageSize=1000' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'data' => [
                        ['siteId' => 'site-001', 'name' => 'Main Branch'],
                        ['siteId' => 'site-002', 'name' => 'North Branch'],
                    ],
                ],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post('/admin/controller/sync-sites', [
                'controller_name' => 'Pilot Controller',
                'base_url' => 'https://localhost:8043',
                'site_identifier' => null,
                'site_name' => null,
                'portal_base_url' => 'https://portal.example.com',
                'username' => 'admin',
                'password' => 'super-secret',
                'api_client_id' => 'pilot-client',
                'api_client_secret' => 'pilot-secret',
                'default_session_minutes' => 60,
            ])
            ->assertRedirect('/admin/controller')
            ->assertSessionHas('success', 'Omada site sync finished. 2 sites scanned, 2 created, 0 updated.');

        $this->assertDatabaseHas('sites', [
            'name' => 'Main Branch',
            'omada_site_id' => 'site-001',
        ]);

        $this->assertDatabaseHas('sites', [
            'name' => 'North Branch',
            'omada_site_id' => 'site-002',
        ]);

        $this->assertSame(2, Site::query()->whereNotNull('omada_site_id')->count());
    }

    public function test_logout_does_not_delete_controller_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'kencanillo',
            'password' => 'super-secret',
            'default_session_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->post(route('logout'))
            ->assertRedirect('/');

        $settings = ControllerSetting::query()->firstOrFail();

        $this->assertSame('kencanillo', $settings->username);
        $this->assertSame('super-secret', $settings->password);
        $this->assertSame('https://localhost:8043', $settings->base_url);
    }

    public function test_saved_controller_settings_are_still_visible_after_logout_and_login_cycle(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'kencanillo',
            'password' => 'super-secret',
            'default_session_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->actingAs($admin)
            ->get('/admin/controller')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ControllerSettings')
                ->where('controllerSettings.username', 'kencanillo')
                ->where('controllerSettings.has_password', true));
    }

    public function test_failed_connection_test_keeps_existing_username_and_password_when_submitted_blank(): void
    {
        Http::fake([
            'https://localhost:8043/api/info' => Http::response([
                'omadacName' => 'Pilot Omada',
                'omadacVersion' => '6.1.0.19',
                'apiVer' => '3',
                'omadacId' => 'controller-id',
            ]),
            'https://localhost:8043/openapi/authorize/token?grant_type=client_credentials' => Http::response([
                'errorCode' => -1,
                'msg' => 'Invalid OpenAPI credentials.',
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'site_name' => 'Old Site',
            'username' => 'kencanillo',
            'password' => 'super-secret',
            'default_session_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->post('/admin/controller/test-connection', [
                'controller_name' => 'Pilot Controller',
                'base_url' => 'https://localhost:8043',
                'site_identifier' => null,
                'site_name' => 'Main Branch',
                'portal_base_url' => 'https://portal.example.com',
                'username' => '',
                'password' => '',
                'api_client_id' => 'pilot-client',
                'api_client_secret' => 'pilot-secret',
                'default_session_minutes' => 60,
            ])
            ->assertRedirect('/admin/controller')
            ->assertSessionHas('error', 'Invalid OpenAPI credentials. Settings were saved. Fix the credentials and test again.');

        $settings = ControllerSetting::query()->firstOrFail();

        $this->assertSame('kencanillo', $settings->username);
        $this->assertSame('super-secret', $settings->password);
        $this->assertSame('Main Branch', $settings->site_name);
    }
}
