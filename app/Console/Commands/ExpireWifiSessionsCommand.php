<?php

namespace App\Console\Commands;

use App\Services\WifiSessionService;
use Illuminate\Console\Command;

class ExpireWifiSessionsCommand extends Command
{
    protected $signature = 'wifi:expire-sessions';

    protected $description = 'Deactivate all expired active WiFi sessions.';

    public function handle(WifiSessionService $wifiSessionService): int
    {
        $expiredCount = $wifiSessionService->expireAllDueSessions();
        $this->info("Expired {$expiredCount} session(s).");

        return self::SUCCESS;
    }
}
