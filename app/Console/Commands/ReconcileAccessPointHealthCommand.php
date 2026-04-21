<?php

namespace App\Console\Commands;

use App\Services\AccessPointHealthService;
use Illuminate\Console\Command;

class ReconcileAccessPointHealthCommand extends Command
{
    protected $signature = 'omada:reconcile-access-point-health';

    protected $description = 'Expire stale AP health snapshots into explicit stale_unknown state.';

    public function handle(AccessPointHealthService $healthService): int
    {
        $healthService->noteReconcileHeartbeat();
        $updated = $healthService->reconcileStaleHealth();

        $this->info("AP health reconciliation finished. {$updated} access points moved to stale_unknown.");

        return self::SUCCESS;
    }
}
