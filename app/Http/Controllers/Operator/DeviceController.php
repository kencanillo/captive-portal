<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\ControllerSetting;
use App\Services\OmadaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DeviceController extends Controller
{
    public function index(): Response
    {
        $operator = request()->user()->loadMissing('operator.sites')->operator;
        $siteIds = $operator->sites()->pluck('id');

        $pendingDevices = AccessPoint::query()
            ->whereIn('site_id', $siteIds)
            ->where('claim_status', AccessPoint::CLAIM_STATUS_PENDING)
            ->with('site:id,name')
            ->get()
            ->map(fn (AccessPoint $ap) => [
                'id' => $ap->id,
                'name' => $ap->name,
                'mac_address' => $ap->mac_address,
                'model' => $ap->model,
                'site_name' => $ap->site?->name,
                'last_synced_at' => optional($ap->last_synced_at)?->toDateTimeString(),
            ]);

        $connectedDevices = AccessPoint::query()
            ->whereIn('site_id', $siteIds)
            ->where('claim_status', AccessPoint::CLAIM_STATUS_CLAIMED)
            ->where('is_online', true)
            ->with('site:id,name')
            ->get()
            ->map(fn (AccessPoint $ap) => [
                'id' => $ap->id,
                'name' => $ap->name,
                'mac_address' => $ap->mac_address,
                'model' => $ap->model,
                'site_name' => $ap->site?->name,
                'last_synced_at' => optional($ap->last_synced_at)?->toDateTimeString(),
            ]);

        $failedDevices = AccessPoint::query()
            ->whereIn('site_id', $siteIds)
            ->where('claim_status', AccessPoint::CLAIM_STATUS_CLAIMED)
            ->where('is_online', false)
            ->with('site:id,name')
            ->get()
            ->map(fn (AccessPoint $ap) => [
                'id' => $ap->id,
                'name' => $ap->name,
                'mac_address' => $ap->mac_address,
                'model' => $ap->model,
                'site_name' => $ap->site?->name,
                'last_synced_at' => optional($ap->last_synced_at)?->toDateTimeString(),
            ]);

        return Inertia::render('Operator/Devices', [
            'pendingDevices' => $pendingDevices,
            'connectedDevices' => $connectedDevices,
            'failedDevices' => $failedDevices,
        ]);
    }

    public function adopt(Request $request, OmadaService $omadaService): RedirectResponse
    {
        $request->validate([
            'access_point_id' => 'required|exists:access_points,id',
        ]);

        $operator = request()->user()->loadMissing('operator.sites')->operator;
        $siteIds = $operator->sites()->pluck('id');

        $accessPoint = AccessPoint::query()
            ->where('id', $request->access_point_id)
            ->whereIn('site_id', $siteIds)
            ->where('claim_status', AccessPoint::CLAIM_STATUS_PENDING)
            ->firstOrFail();

        $settings = ControllerSetting::singleton();

        try {
            $omadaService->adoptDevice($settings, $accessPoint->mac_address);

            return redirect()
                ->route('operator.devices.index')
                ->with('success', "Device {$accessPoint->name} adopted successfully.");
        } catch (Throwable $exception) {
            return redirect()
                ->route('operator.devices.index')
                ->with('error', 'Failed to adopt device: '.$exception->getMessage());
        }
    }
}