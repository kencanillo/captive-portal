<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\PortalDeviceContextService;
use App\Support\PortalPlanViewData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CaptivePortalController extends Controller
{
    public function __invoke(Request $request, PortalDeviceContextService $portalDeviceContextService): Response
    {
        $startedAt = microtime(true);
        $requestId = (string) Str::uuid();
        $initialPortalContext = $portalDeviceContextService->buildInitialContext($request);
        $trustedPortalRequest = $portalDeviceContextService->resolveTrustedClientMacFromRequest($request);
        $initialPlans = [];
        $plansPrefetched = false;

        try {
            $initialPlans = PortalPlanViewData::collection(
                Plan::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('price')
                    ->get()
            );
            $plansPrefetched = true;
        } catch (Throwable $exception) {
            Log::warning('Portal plan prefetch failed for shell response.', [
                'request_id' => $requestId,
                'message' => $exception->getMessage(),
            ]);
        }

        Log::info('Portal entry request received.', [
            'request_id' => $requestId,
            'source_ip' => $request->ip(),
            'client_ip' => $initialPortalContext['client_ip'],
            'captive_context_present' => $trustedPortalRequest['captive_context_present'],
            'resolved_mac' => $trustedPortalRequest['mac_address'],
            'resolution_trusted' => $trustedPortalRequest['trusted'],
            'rejected_reason' => $trustedPortalRequest['rejected_reason'],
            'plans_prefetched' => $plansPrefetched,
            'plans_count' => count($initialPlans),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return Inertia::render('Public/PlanSelection', [
            'portalRequestId' => $requestId,
            'deviceContextUrl' => url('/api/portal/device-context').($request->getQueryString() ? "?{$request->getQueryString()}" : ''),
            'plansUrl' => url('/api/portal/plans').($request->getQueryString() ? "?{$request->getQueryString()}" : ''),
            'deviceContextTimeoutMs' => (int) config('portal.bootstrap_timeout_seconds', 8) * 1000,
            'initialPlans' => $initialPlans,
            'plansPrefetched' => $plansPrefetched,
            'initialPortalContext' => $initialPortalContext,
            'initialDeviceContextState' => [
                'status' => $trustedPortalRequest['trusted'] ? 'pending' : 'failed',
                'error_code' => $trustedPortalRequest['error_code'],
            ],
        ]);
    }
}
