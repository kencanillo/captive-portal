<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectPlanRequest;
use App\Models\Plan;
use App\Support\PortalTokenService;
use App\Services\WifiSessionService;
use App\Support\ApiResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PlanSelectionApiController extends Controller
{
    use ApiResponse;

    public function __invoke(
        SelectPlanRequest $request,
        WifiSessionService $wifiSessionService,
        PortalTokenService $portalTokenService
    )
    {
        try {
            $portalContext = $portalTokenService->resolvePortalContext($request->string('portal_token')->toString());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'portal_token' => ['The portal context is invalid or expired. Refresh the captive portal page and try again.'],
            ]);
        }

        $plan = Plan::query()->findOrFail($request->integer('plan_id'));
        $session = $wifiSessionService->createSession(
            (string) $portalContext['mac_address'],
            $plan,
            collect($portalContext)->only([
                'ap_mac',
                'ap_name',
                'site_name',
                'ssid_name',
                'radio_id',
                'client_ip',
            ])->all(),
            $request->getClientRegistrationData()
        );

        return $this->success([
            'session_token' => $portalTokenService->issueSessionToken($session),
            'plan' => $plan,
        ], 'Plan selected successfully.', 201);
    }
}
