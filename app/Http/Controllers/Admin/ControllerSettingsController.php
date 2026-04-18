<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveControllerSettingsRequest;
use App\Models\ControllerSetting;
use App\Models\Site;
use App\Services\OmadaService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ControllerSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = ControllerSetting::singleton();

        return Inertia::render('Admin/ControllerSettings', [
            'controllerSettings' => [
                'controller_name' => $settings->controller_name,
                'base_url' => $settings->base_url,
                'site_identifier' => $settings->site_identifier,
                'site_name' => $settings->site_name,
                'portal_base_url' => $settings->portal_base_url,
                'username' => $settings->username,
                'hotspot_operator_username' => $settings->hotspot_operator_username,
                'api_client_id' => $settings->api_client_id,
                'default_session_minutes' => $settings->default_session_minutes,
                'last_tested_at' => optional($settings->last_tested_at)?->toDateTimeString(),
                'has_password' => filled($settings->getRawOriginal('password')),
                'has_hotspot_operator_password' => filled($settings->getRawOriginal('hotspot_operator_password')),
                'has_api_client_secret' => filled($settings->getRawOriginal('api_client_secret')),
            ],
            'canSyncSites' => $settings->canSyncSites(),
            'syncedSites' => Site::query()
                ->whereNotNull('omada_site_id')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'omada_site_id'])
                ->map(fn (Site $site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'slug' => $site->slug,
                    'omada_site_id' => $site->omada_site_id,
                ]),
        ]);
    }

    public function update(SaveControllerSettingsRequest $request)
    {
        $settings = ControllerSetting::query()->first() ?? new ControllerSetting;

        $this->applyValidatedSettings($settings, $request->validated())->save();

        return redirect()
            ->route('admin.controller.edit')
            ->with('success', 'Controller settings updated.');
    }

    public function testConnection(SaveControllerSettingsRequest $request, OmadaService $omadaService)
    {
        $settings = ControllerSetting::query()->first() ?? new ControllerSetting;

        $this->applyValidatedSettings($settings, $request->validated());

        try {
            $result = $omadaService->testConnection($settings);

            $settings->forceFill([
                'last_tested_at' => now(),
            ])->save();

            $version = $result['version'] ? " v{$result['version']}" : '';

            return redirect()
                ->route('admin.controller.edit')
                ->with('success', "Connected to {$result['controller_name']}{$version}. Settings were saved and verified.");
        } catch (Throwable $exception) {
            $settings->save();

            return redirect()
                ->route('admin.controller.edit')
                ->with('error', $exception->getMessage().' Settings were saved. Fix the credentials and test again.');
        }
    }

    public function syncSites(SaveControllerSettingsRequest $request, OmadaService $omadaService): RedirectResponse
    {
        $settings = ControllerSetting::query()->first() ?? new ControllerSetting;
        $this->applyValidatedSettings($settings, $request->validated())->save();

        try {
            $result = $omadaService->syncSites($settings);

            return redirect()
                ->route('admin.controller.edit')
                ->with('success', "Omada site sync finished. {$result['total']} sites scanned, {$result['created']} created, {$result['updated']} updated.");
        } catch (Throwable $exception) {
            return redirect()
                ->route('admin.controller.edit')
                ->with('error', $exception->getMessage());
        }
    }

    private function applyValidatedSettings(ControllerSetting $settings, array $validated): ControllerSetting
    {
        $preserveOnBlank = [
            'username',
            'password',
            'hotspot_operator_username',
            'hotspot_operator_password',
            'api_client_id',
            'api_client_secret',
        ];

        foreach ($preserveOnBlank as $field) {
            if (blank($validated[$field] ?? null) && filled($settings->{$field})) {
                unset($validated[$field]);
            }
        }

        return tap($settings)->fill($validated);
    }
}
