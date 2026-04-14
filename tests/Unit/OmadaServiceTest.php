<?php

namespace Tests\Unit;

use App\Models\ControllerSetting;
use App\Services\OmadaService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OmadaServiceTest extends TestCase
{
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
}
