<?php

namespace App\Console\Commands;

use App\Jobs\RecordQueueWorkerHeartbeatJob;
use Illuminate\Console\Command;

class DispatchQueueWorkerHeartbeatCommand extends Command
{
    protected $signature = 'ops:dispatch-queue-worker-heartbeat';

    protected $description = 'Dispatch a lightweight queued heartbeat so worker health is explicit instead of inferred from business activity.';

    public function handle(): int
    {
        RecordQueueWorkerHeartbeatJob::dispatch();

        $this->info('Queue worker heartbeat dispatched.');

        return self::SUCCESS;
    }
}
