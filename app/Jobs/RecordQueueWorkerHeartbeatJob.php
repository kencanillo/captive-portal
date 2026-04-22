<?php

namespace App\Jobs;

use App\Services\AutomationHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordQueueWorkerHeartbeatJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        return 'ops:queue-worker-heartbeat';
    }

    public function handle(AutomationHealthService $automationHealthService): void
    {
        $automationHealthService->recordQueueWorkerHeartbeat();
    }
}
