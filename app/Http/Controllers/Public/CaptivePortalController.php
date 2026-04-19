<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\PortalDeviceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CaptivePortalController extends Controller
{
    public function __invoke(Request $request, PortalDeviceContextService $portalDeviceContextService): Response
    {
        $startedAt = microtime(true);
        $requestId = (string) Str::uuid();
        $initialPortalContext = $portalDeviceContextService->buildInitialContext($request);

        Log::info('Portal page shell prepared.', [
            'request_id' => $requestId,
            'client_ip' => $initialPortalContext['client_ip'],
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'has_query_mac' => filled($request->query('clientMac') ?: $request->query('client_mac')),
        ]);

        return Inertia::render('Public/PlanSelection', [
            'portalRequestId' => $requestId,
            'deviceContextUrl' => url('/api/portal/device-context').($request->getQueryString() ? "?{$request->getQueryString()}" : ''),
            'plansUrl' => url('/api/portal/plans').($request->getQueryString() ? "?{$request->getQueryString()}" : ''),
            'deviceContextTimeoutMs' => (int) config('portal.bootstrap_timeout_seconds', 8) * 1000,
            'initialPortalContext' => $initialPortalContext,
        ]);
    }
}
