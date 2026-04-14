<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\SelectPlanRequest;
use App\Models\Plan;
use App\Services\WifiSessionService;
use App\Support\ApiResponse;

class PlanSelectionApiController extends Controller
{
    use ApiResponse;

    public function __invoke(SelectPlanRequest $request, WifiSessionService $wifiSessionService)
    {
        $plan = Plan::query()->findOrFail($request->integer('plan_id'));
        $session = $wifiSessionService->createSession(
            $request->string('mac_address')->toString(),
            $plan,
            $request->safe()->only([
                'ap_mac',
                'ap_name',
                'site_name',
                'ssid_name',
                'client_ip',
            ]),
            $request->getClientRegistrationData()
        );

        return $this->success([
            'session_id' => $session->id,
            'plan' => $plan,
        ], 'Plan selected successfully.', 201);
    }
}
