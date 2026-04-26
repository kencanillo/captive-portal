<?php

namespace App\Console\Commands;

use App\Services\AutomationHealthService;
use Illuminate\Console\Command;

class RecordSchedulerHeartbeatCommand extends Command
{
    protected $signature = 'ops:record-scheduler-heartbeat';

    protected $description = 'Record a scheduler heartbeat so runtime automation health can detect stale or missing scheduler activity.';

    public function handle(AutomationHealthService $automationHealthService): int
    {
        $automationHealthService->recordSchedulerHeartbeat();

        $this->info('Scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}
