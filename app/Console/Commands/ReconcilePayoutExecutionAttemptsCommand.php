<?php

namespace App\Console\Commands;

use App\Services\OperatorPayoutService;
use App\Services\PayoutExecutionOpsService;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePayoutExecutionAttemptsCommand extends Command
{
    protected $signature = 'payouts:reconcile-execution-attempts {--limit= : Maximum number of payout execution attempts to reconcile}';

    protected $description = 'Reconcile stale or ambiguous payout execution attempts through the configured payout provider.';

    public function handle(
        PayoutExecutionOpsService $payoutExecutionOpsService,
        OperatorPayoutService $operatorPayoutService,
    ): int {
        $runtime = $payoutExecutionOpsService->runtimeHealth();

        if (! ($runtime['provider_readiness']['ready'] ?? false)) {
            $this->warn($runtime['provider_readiness']['blocking_reason'] ?? 'Payout execution provider is not ready.');
            $payoutExecutionOpsService->noteReconcileHeartbeat();

            return self::SUCCESS;
        }

        $limit = max(1, (int) ($this->option('limit') ?: ($runtime['background_reconcile_limit'] ?? 25)));
        $candidates = $payoutExecutionOpsService->backgroundReconcileCandidates($limit);
        $reconciled = 0;
        $failed = 0;

        foreach ($candidates as $attempt) {
            try {
                $operatorPayoutService->reconcileExecutionAttemptInBackground($attempt);
                $reconciled++;
            } catch (Throwable $exception) {
                $failed++;
                $this->warn(sprintf(
                    'Execution attempt %s reconcile failed: %s',
                    $attempt->execution_reference,
                    $exception->getMessage()
                ));
            }
        }

        $payoutExecutionOpsService->noteReconcileHeartbeat();

        $this->info(sprintf(
            'Payout execution reconcile finished. %d reconciled, %d failed, %d queued for review.',
            $reconciled,
            $failed,
            $candidates->count()
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
