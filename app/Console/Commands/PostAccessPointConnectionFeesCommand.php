<?php

namespace App\Console\Commands;

use App\Services\AccessPointBillingService;
use Illuminate\Console\Command;
use Throwable;

class PostAccessPointConnectionFeesCommand extends Command
{
    protected $signature = 'billing:post-access-point-fees';

    protected $description = 'Post one-time AP connection-fee ledger entries for eligible access points.';

    public function handle(AccessPointBillingService $billingService): int
    {
        try {
            $result = $billingService->postConnectionFees();

            $this->info(
                "AP billing finished. {$result['posted']} posted, {$result['already_billed']} already billed, {$result['blocked']} blocked, {$result['reversed']} reversed, {$result['unbilled']} unbilled."
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
