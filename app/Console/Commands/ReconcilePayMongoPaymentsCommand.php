<?php

namespace App\Console\Commands;

use App\Services\PayMongoQrPhService;
use Illuminate\Console\Command;

class ReconcilePayMongoPaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile-paymongo {--limit=50 : Maximum number of pending QR payments to recheck per run}';

    protected $description = 'Recheck open PayMongo QR payments and activate paid sessions without relying on an open captive browser.';

    public function handle(PayMongoQrPhService $payMongoQrPhService): int
    {
        $summary = $payMongoQrPhService->reconcilePendingPayments((int) $this->option('limit'));

        $this->info(sprintf(
            'Checked %d payment(s). Paid: %d. Activated: %d. Awaiting: %d. Expired: %d. Failed: %d. Errors: %d.',
            $summary['checked'],
            $summary['paid'],
            $summary['released'],
            $summary['awaiting_payment'],
            $summary['expired'],
            $summary['failed'],
            $summary['errors'],
        ));

        return self::SUCCESS;
    }
}
