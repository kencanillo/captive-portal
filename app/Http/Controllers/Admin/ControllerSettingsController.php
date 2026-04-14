<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveControllerSettingsRequest;
use App\Models\ControllerSetting;
use App\Services\OmadaService;
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
                'api_client_id' => $settings->api_client_id,
                'default_session_minutes' => $settings->default_session_minutes,
                'last_tested_at' => optional($settings->last_tested_at)?->toDateTimeString(),
                'has_password' => filled($settings->getRawOriginal('password')),
                'has_api_client_secret' => filled($settings->getRawOriginal('api_client_secret')),
            ],
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

    private function applyValidatedSettings(ControllerSetting $settings, array $validated): ControllerSetting
    {
        $preserveOnBlank = [
            'username',
            'password',
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
