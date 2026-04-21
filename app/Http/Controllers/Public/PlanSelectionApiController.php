<?php

namespace App\Http\Controllers\Public;

use App\Exceptions\TransferRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SelectPlanRequest;
use App\Models\Plan;
use App\Services\DeviceTransferService;
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
        PortalTokenService $portalTokenService,
        DeviceTransferService $deviceTransferService,
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
                $request->getClientRegistrationData()
            );
        } catch (TransferRequiredException $exception) {
            $transferRequest = null;

            try {
                $transferRequest = $deviceTransferService->createOrReuseFromPortalFlow(
                    $exception->client(),
                    $exception->activeSession(),
                    $exception->requestedMacAddress(),
                    $request->input('client_registration.phone_number'),
                    collect($portalContext)->only([
                        'ap_mac',
                        'ap_name',
                        'site_name',
                        'ssid_name',
                        'client_ip',
                    ])->all(),
                );
            } catch (RuntimeException $creationException) {
                return response()->json([
                    'success' => false,
                    'message' => $creationException->getMessage(),
                    'data' => $exception->context(),
                ], 429);
            }

            return response()->json([
                'success' => false,
                'message' => $transferRequest
                    ? 'Device replacement request submitted for admin review.'
                    : $exception->getMessage(),
                'data' => array_merge(
                    $exception->context(),
                    ['transfer_request' => $deviceTransferService->toPublicPayload($transferRequest)]
                ),
            ], 409);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'client_registration.pin' => [$exception->getMessage()],
            ]);
        }

        return $this->success([
            'session_token' => $portalTokenService->issueSessionToken($session),
            'plan' => $plan,
        ], 'Plan selected successfully.', 201);
    }
}
