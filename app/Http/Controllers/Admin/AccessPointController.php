<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\ControllerSetting;
use App\Services\OmadaService;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AccessPointController extends Controller
{
    public function index(): Response
    {
        $settings = ControllerSetting::singleton();

        return Inertia::render('Admin/AccessPoints', [
            'syncConfigured' => $settings->canSyncAccessPoints(),
            'statusSummary' => [
                'connected' => AccessPoint::query()->where('is_online', true)->count(),
                'pending' => AccessPoint::query()->where('claim_status', AccessPoint::CLAIM_STATUS_PENDING)->count(),
                'failed' => AccessPoint::query()->where('claim_status', AccessPoint::CLAIM_STATUS_ERROR)->count(),
            ],
            'accessPoints' => AccessPoint::query()
                ->with('site:id,name')
                ->orderByDesc('is_online')
                ->orderByRaw("claim_status = 'claimed' desc")
                ->orderBy('name')
                ->get()
                ->map(fn (AccessPoint $accessPoint) => [
                    'id' => $accessPoint->id,
                    'name' => $accessPoint->name,
                    'serial_number' => $accessPoint->serial_number,
                    'mac_address' => $accessPoint->mac_address,
                    'site_name' => $accessPoint->site?->name,
                    'vendor' => $accessPoint->vendor,
                    'model' => $accessPoint->model,
                    'ip_address' => $accessPoint->ip_address,
                    'omada_device_id' => $accessPoint->omada_device_id,
                    'claim_status' => $accessPoint->claim_status,
                    'claimed_at' => optional($accessPoint->claimed_at)?->toDateTimeString(),
                    'last_seen_at' => optional($accessPoint->last_seen_at)?->toDateTimeString(),
                    'last_synced_at' => optional($accessPoint->last_synced_at)?->toDateTimeString(),
                    'is_online' => $accessPoint->is_online,
                    'status_label' => $this->statusLabel($accessPoint),
                ]),
        ]);
    }

    public function sync(OmadaService $omadaService)
    {
        $settings = ControllerSetting::query()->first();

        if (! $settings) {
            return redirect()
                ->route('admin.access-points.index')
                ->with('error', 'Controller settings are missing. Save them before syncing devices.');
        }

        if (! $settings->canSyncAccessPoints()) {
            return redirect()
                ->route('admin.access-points.index')
                ->with('error', 'Omada AP sync still requires a local controller username/password. Save them in Controller Settings before syncing devices.');
        }

        try {
            $result = $omadaService->syncAccessPoints($settings);
            $settings->forceFill([
                'last_tested_at' => now(),
            ])->save();

            return redirect()
                ->route('admin.access-points.index')
                ->with(
                    'success',
                    "Omada sync finished. {$result['total']} devices scanned, {$result['claimed']} claimed, {$result['pending']} pending, {$result['created']} created, {$result['updated']} updated."
                );
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.access-points.index')
                ->with('error', $exception->getMessage());
        }
    }

    private function statusLabel(AccessPoint $accessPoint): string
    {
        if ($accessPoint->claim_status === AccessPoint::CLAIM_STATUS_ERROR) {
            return 'Failed';
        }

        if ($accessPoint->claim_status === AccessPoint::CLAIM_STATUS_PENDING) {
            return 'Pending';
        }

        if ($accessPoint->is_online) {
            return 'Connected';
        }

        return 'Offline';
    }
}
