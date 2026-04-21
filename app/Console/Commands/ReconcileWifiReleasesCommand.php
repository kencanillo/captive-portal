<?php

namespace App\Console\Commands;

use App\Services\WifiSessionReleaseService;
use Illuminate\Console\Command;

class ReconcileWifiReleasesCommand extends Command
{
    protected $signature = 'wifi:reconcile-releases {--limit=100}';

    protected $description = 'Reconcile uncertain or stuck WiFi release incidents against Omada state.';

    public function handle(WifiSessionReleaseService $wifiSessionReleaseService): int
    {
        $processed = $wifiSessionReleaseService->reconcileOutstandingSessions((int) $this->option('limit'));
        $this->info("Reconciled {$processed} release incident(s).");

        return self::SUCCESS;
    }
}
