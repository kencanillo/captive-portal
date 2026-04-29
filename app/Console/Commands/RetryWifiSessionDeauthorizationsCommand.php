<?php

namespace App\Console\Commands;

use App\Services\WifiSessionService;
use Illuminate\Console\Command;

class RetryWifiSessionDeauthorizationsCommand extends Command
{
    protected $signature = 'wifi:retry-deauthorizations {--limit=100 : Maximum pending deauthorizations to retry per run}';

    protected $description = 'Retry failed Omada deauthorizations for expired WiFi sessions.';

    public function handle(WifiSessionService $wifiSessionService): int
    {
        $summary = $wifiSessionService->retryPendingDeauthorizations((int) $this->option('limit'));

        $this->info(sprintf(
            'Retried %d pending deauthorization(s): %d succeeded, %d failed, %d manual required.',
            $summary['processed'],
            $summary['succeeded'],
            $summary['failed'],
            $summary['manual_required'],
        ));

        return self::SUCCESS;
    }
}
