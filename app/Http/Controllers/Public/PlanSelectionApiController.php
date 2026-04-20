<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\DeviceDecisionRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SelectPlanRequest;
use App\Models\Plan;
use App\Support\PortalTokenService;
use App\Services\WifiSessionService;
use App\Support\ApiResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

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
        try {
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
                $request->getClientRegistrationData(),
                $request->getDeviceOptions() + [
                    'request_id' => $request->header('X-Portal-Request-Id'),
                ]
            );
        } catch (DeviceDecisionRequiredException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'data' => [
                    'decision' => $exception->decision(),
                ],
            ], 409);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'client_registration.pin' => [$exception->getMessage()],
            ]);
        }

        return $this->success([
            'session_token' => $portalTokenService->issueSessionToken($session),
            'plan' => $plan,
            'payment_required' => in_array($session->payment_status, [
                \App\Models\WifiSession::PAYMENT_STATUS_PENDING,
                \App\Models\WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
                \App\Models\WifiSession::PAYMENT_STATUS_FAILED,
                \App\Models\WifiSession::PAYMENT_STATUS_EXPIRED,
            ], true),
            'session_status' => $session->session_status,
            'payment_status' => $session->payment_status,
        ], 'Plan selected successfully.', 201);
    }
}
