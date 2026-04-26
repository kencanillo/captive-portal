<?php

namespace App\Console\Commands;

use App\Models\ControllerSetting;
use App\Services\OmadaService;
use Illuminate\Console\Command;
use Throwable;

class SyncOmadaAccessPointsCommand extends Command
{
    protected $signature = 'omada:sync-access-points';

    protected $description = 'Sync adopted and pending access points from Omada into the local admin inventory.';

    public function handle(OmadaService $omadaService): int
    {
        $settings = ControllerSetting::query()->first();

        if (! $settings) {
            $this->info('Skipping Omada AP sync because no controller settings exist yet.');

            return self::SUCCESS;
        }

        if (! $settings->canSyncAccessPoints()) {
            $this->info('Skipping Omada AP sync because OpenAPI client credentials are missing.');

            return self::SUCCESS;
        }

        try {
            $result = $omadaService->syncAccessPoints($settings);

            $settings->forceFill([
                'last_tested_at' => now(),
            ])->save();

            $this->info(
                "Omada AP sync finished. {$result['total']} devices scanned, {$result['claimed']} claimed, {$result['pending']} pending, {$result['created']} created, {$result['updated']} updated."
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
