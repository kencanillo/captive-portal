<?php

namespace App\Http\Controllers;

use App\Models\WifiSession;
use App\Services\ManualClientAuthorizationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ManualAuthorizationController extends Controller
{
    public function store(Request $request, ManualClientAuthorizationService $manualClientAuthorizationService): RedirectResponse
    {
        $validated = $request->validate([
            'wifi_session_id' => ['nullable', 'integer', 'exists:wifi_sessions,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'client_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'mac_address' => ['required_without:wifi_session_id', 'nullable', 'string', 'max:40'],
            'plan_id' => ['required', 'integer'],
            'site_id' => ['nullable', 'integer'],
            'access_point_id' => ['nullable', 'integer'],
            'ap_name' => ['nullable', 'string', 'max:120'],
            'ap_mac' => ['nullable', 'string', 'max:40'],
            'ssid_name' => ['nullable', 'string', 'max:120'],
            'radio_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user()->loadMissing('operator');
        $targetSession = null;

        if (! empty($validated['wifi_session_id'])) {
            $targetSession = WifiSession::query()->find($validated['wifi_session_id']);
        }

        $siteId = $targetSession?->site_id ?? ((int) ($validated['site_id'] ?? 0) ?: null);
        $accessPointId = $targetSession?->access_point_id ?? ((int) ($validated['access_point_id'] ?? 0) ?: null);

        if (! $user->can('manual-authorize-client', [$siteId, $accessPointId])) {
            return back()->with('error', 'You can only authorize clients connected to your assigned site or access point.');
        }

        try {
            $session = $manualClientAuthorizationService->authorize($user, $validated);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($session->release_status === WifiSession::RELEASE_STATUS_SUCCEEDED) {
            return back()->with('success', 'Client authorized successfully until '.$session->end_time?->toDateTimeString().'.');
        }

        return back()->with('error', 'Session was created but Omada authorization failed. Please retry.');
    }

    public function retry(Request $request, WifiSession $wifiSession, ManualClientAuthorizationService $manualClientAuthorizationService): RedirectResponse
    {
        $user = $request->user()->loadMissing('operator');

        if ($user->is_admin) {
            try {
                $session = $manualClientAuthorizationService->retry($wifiSession, $user);
            } catch (RuntimeException $exception) {
                return back()->with('error', $exception->getMessage());
            }
        } elseif ($user->can('manual-authorize-client', [$wifiSession->site_id, $wifiSession->access_point_id])) {
            try {
                $session = $manualClientAuthorizationService->retry($wifiSession, $user);
            } catch (RuntimeException $exception) {
                return back()->with('error', $exception->getMessage());
            }
        } else {
            return back()->with('error', 'You can only authorize clients connected to your assigned site or access point.');
        }

        if ($session->release_status === WifiSession::RELEASE_STATUS_SUCCEEDED) {
            return back()->with('success', 'Client authorized successfully until '.$session->end_time?->toDateTimeString().'.');
        }

        return back()->with('error', 'Session was created but Omada authorization failed. Please retry.');
    }
}
