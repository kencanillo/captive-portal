<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAccessPointRequest;
use App\Models\AccessPoint;
use App\Models\ControllerSetting;
use App\Models\Site;
use App\Services\OmadaService;
use Illuminate\Support\Str;
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
            'accessPoints' => AccessPoint::query()
                ->with('site:id,name')
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
                    'custom_ssid' => $accessPoint->custom_ssid,
                    'voucher_ssid_name' => $accessPoint->voucher_ssid_name,
                    'allow_client_pause' => $accessPoint->allow_client_pause,
                    'block_tethering' => $accessPoint->block_tethering,
                    'is_portal_enabled' => $accessPoint->is_portal_enabled,
                    'is_online' => $accessPoint->is_online,
                ]),
        ]);
    }

    public function store(SaveAccessPointRequest $request)
    {
        $validated = $request->validated();

        AccessPoint::query()->create($this->payload($validated));

        return redirect()
            ->route('admin.access-points.index')
            ->with('success', 'Access point saved.');
    }

    public function update(SaveAccessPointRequest $request, AccessPoint $accessPoint)
    {
        $accessPoint->update($this->payload($request->validated(), $accessPoint));

        return redirect()
            ->route('admin.access-points.index')
            ->with('success', 'Access point updated.');
    }

    public function destroy(AccessPoint $accessPoint)
    {
        $accessPoint->delete();

        return redirect()
            ->route('admin.access-points.index')
            ->with('success', 'Access point deleted.');
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

    private function payload(array $validated, ?AccessPoint $accessPoint = null): array
    {
        $siteName = trim((string) ($validated['site_name'] ?? ''));
        $site = $siteName !== '' ? $this->firstOrCreateSite($siteName) : null;
        $claimStatus = $validated['claim_status'];
        $claimedAt = $accessPoint?->claimed_at;

        if ($claimStatus === AccessPoint::CLAIM_STATUS_CLAIMED && ! $claimedAt) {
            $claimedAt = now();
        }

        if ($claimStatus !== AccessPoint::CLAIM_STATUS_CLAIMED) {
            $claimedAt = null;
        }

        unset($validated['site_name']);

        return [
            ...$validated,
            'site_id' => $site?->id,
            'claimed_at' => $claimedAt,
            'last_synced_at' => $accessPoint?->last_synced_at,
        ];
    }

    private function firstOrCreateSite(string $siteName): Site
    {
        $site = Site::query()->firstWhere('name', $siteName);

        if ($site) {
            return $site;
        }

        $baseSlug = Str::slug($siteName) ?: 'location';
        $slug = $baseSlug;
        $suffix = 2;

        while (Site::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return Site::query()->create([
            'name' => $siteName,
            'slug' => $slug,
        ]);
    }
}
