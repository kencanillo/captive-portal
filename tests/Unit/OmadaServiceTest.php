<?php

namespace Tests\Unit;

use App\Models\ControllerSetting;
use App\Models\Site;
use App\Models\WifiSession;
use App\Services\OmadaService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class OmadaServiceTest extends TestCase
{
    public function test_client_respects_ssl_verification_config_flag(): void
    {
        $service = app(OmadaService::class);

        config()->set('services.omada.verify_ssl', true);
        $verifiedClient = $this->invokeClient($service, [
            'base_url' => 'https://76.13.187.98:8043',
        ]);

        config()->set('services.omada.verify_ssl', false);
        $unverifiedClient = $this->invokeClient($service, [
            'base_url' => 'https://76.13.187.98:8043',
        ]);

        $verifiedOptions = $this->pendingRequestOptions($verifiedClient);
        $unverifiedOptions = $this->pendingRequestOptions($unverifiedClient);

        $this->assertTrue($verifiedOptions['verify'] ?? true);
        $this->assertFalse($unverifiedOptions['verify']);
    }

    public function test_client_accepts_timeout_overrides_for_portal_hot_paths(): void
    {
        $service = app(OmadaService::class);

        $client = $this->invokeClient($service, [
            'base_url' => 'https://76.13.187.98:8043',
        ], [
            'connect_timeout' => 1,
            'timeout' => 4,
        ]);

        $options = $this->pendingRequestOptions($client);

        $this->assertSame(1, $options['connect_timeout']);
        $this->assertSame(4, $options['timeout']);
    }

    public function test_test_connection_prefers_openapi_client_credentials_when_present(): void
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

        $service = app(OmadaService::class);

        $result = $service->testConnection(new ControllerSetting([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'wrong-local-user',
            'password' => 'wrong-local-password',
            'api_client_id' => 'pilot-client',
            'api_client_secret' => 'pilot-secret',
        ]));

        $this->assertSame('Pilot Controller', $result['controller_name']);
        $this->assertSame('6.1.0.19', $result['version']);
        $this->assertSame('3', $result['api_version']);

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v2/login'));
    }

    public function test_test_connection_uses_legacy_login_when_openapi_credentials_are_missing(): void
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
            'https://localhost:8043/api/v2/login' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => ['token' => 'abc123'],
            ]),
            'https://localhost:8043/api/v2/controller/setting' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => ['name' => 'Pilot Omada'],
            ]),
        ]);

        $service = app(OmadaService::class);

        $result = $service->testConnection(new ControllerSetting([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'super-secret',
        ]));

        $this->assertSame('Pilot Omada', $result['controller_name']);
        $this->assertSame('6.1.0.19', $result['version']);
        $this->assertSame('3', $result['api_version']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v2/login'));
    }

    public function test_authorize_client_uses_hotspot_login_and_external_portal_auth_endpoint(): void
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
            'https://localhost:8043/controller-id/api/v2/hotspot/login' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => [
                    'token' => 'csrf-token',
                ],
            ]),
            'https://localhost:8043/controller-id/api/v2/hotspot/extPortal/auth' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
            ]),
        ]);

        $service = app(OmadaService::class);

        $session = new WifiSession([
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'ap_mac' => '11:22:33:44:55:66',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'end_time' => Carbon::create(2026, 4, 15, 12, 30, 0, 'Asia/Manila'),
        ]);
        $session->setRelation('site', new Site([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
        ]));

        $service->authorizeClient(new ControllerSetting([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]), $session);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/controller-id/api/v2/hotspot/login')
            && data_get($request->data(), 'name') === 'operator'
            && data_get($request->data(), 'password') === 'secret');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/controller-id/api/v2/hotspot/extPortal/auth')
            && $request->hasHeader('Csrf-Token', 'csrf-token')
            && data_get($request->data(), 'authType') === 4
            && data_get($request->data(), 'clientMac') === 'AA:BB:CC:DD:EE:FF'
            && data_get($request->data(), 'apMac') === '11:22:33:44:55:66'
            && data_get($request->data(), 'ssidName') === 'Guest WiFi'
            && data_get($request->data(), 'radioId') === 1
            && data_get($request->data(), 'site') === 'Main Branch'
            && data_get($request->data(), 'time') === 1776227400000000);
    }

    public function test_deauthorize_client_uses_openapi_unauth_and_disconnect_endpoints(): void
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
            'https://localhost:8043/openapi/v1/controller-id/sites/main-branch/hotspot/clients/AA-BB-CC-DD-EE-FF/unauth' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
                'result' => 'Guest Client',
            ]),
            'https://localhost:8043/openapi/v1/controller-id/sites/main-branch/clients/AA-BB-CC-DD-EE-FF/disconnect' => Http::response([
                'errorCode' => 0,
                'msg' => 'Success.',
            ]),
        ]);

        $service = app(OmadaService::class);

        $session = new WifiSession([
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'ap_mac' => '11:22:33:44:55:66',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
        ]);
        $session->setRelation('site', new Site([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
        ]));

        $service->deauthorizeClient(new ControllerSetting([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'api_client_id' => 'pilot-client',
            'api_client_secret' => 'pilot-secret',
        ]), $session);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/openapi/authorize/token?grant_type=client_credentials')
            && data_get($request->data(), 'client_id') === 'pilot-client'
            && data_get($request->data(), 'client_secret') === 'pilot-secret');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/openapi/v1/controller-id/sites/main-branch/hotspot/clients/AA-BB-CC-DD-EE-FF/unauth')
            && $request->hasHeader('Authorization', 'AccessToken=access-token'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/openapi/v1/controller-id/sites/main-branch/clients/AA-BB-CC-DD-EE-FF/disconnect')
            && $request->hasHeader('Authorization', 'AccessToken=access-token'));
    }

    public function test_get_sites_uses_required_openapi_pagination_parameters(): void
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
                    'data' => [
                        ['siteId' => 'site-001', 'name' => 'Main Branch'],
                        ['siteId' => 'site-002', 'name' => 'North Branch'],
                    ],
                ],
            ]),
        ]);

        $service = app(OmadaService::class);

        $sites = $service->getSites(new ControllerSetting([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'api_client_id' => 'pilot-client',
            'api_client_secret' => 'pilot-secret',
        ]));

        $this->assertCount(2, $sites);
        $this->assertSame('site-001', $sites[0]['siteId']);
        $this->assertSame('Main Branch', $sites[0]['name']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/openapi/v1/controller-id/sites?page=1&pageSize=1000')
            && $request->hasHeader('Authorization', 'AccessToken=access-token'));
    }

    private function invokeClient(OmadaService $service, array $settings, ?array $timeoutProfile = null): PendingRequest
    {
        $method = new ReflectionMethod($service, 'client');
        $method->setAccessible(true);

        /** @var PendingRequest $client */
        $client = $method->invoke($service, array_merge([
            'base_url' => 'https://localhost:8043',
        ], $settings), $timeoutProfile);

        return $client;
    }

    private function pendingRequestOptions(PendingRequest $client): array
    {
        $property = new ReflectionProperty($client, 'options');
        $property->setAccessible(true);

        /** @var array $options */
        $options = $property->getValue($client);

        return $options;
    }
}
