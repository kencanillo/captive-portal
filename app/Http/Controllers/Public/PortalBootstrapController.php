<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\PortalDeviceContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalBootstrapController extends Controller
{
    public function __invoke(Request $request, PortalDeviceContextService $portalDeviceContextService): JsonResponse
    {
        $data = $portalDeviceContextService->resolve($request);

        return response()
            ->json(['data' => $data])
            ->header('X-Portal-Request-Id', (string) ($data['request_id'] ?? ''));
    }
}
