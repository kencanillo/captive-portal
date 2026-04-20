<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchiveUnpaidPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:archive-unpaid-payments {--force}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive unpaid payments older than 24 hours';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting unpaid payment archiving process...');

        $cutoffTime = now()->subHours(24);
        
        $unpaidPayments = Payment::query()
            ->whereIn('status', [
                Payment::STATUS_PENDING,
                Payment::STATUS_AWAITING_PAYMENT,
            ])
            ->where('created_at', '<', $cutoffTime)
            ->whereNull('archived_at')
            ->get();

        if ($unpaidPayments->isEmpty()) {
            $this->info('No unpaid payments older than 24 hours found.');
            return;
        }

        $count = $unpaidPayments->count();
        $this->info("Found {$count} unpaid payments to archive.");

        DB::transaction(function () use ($unpaidPayments) {
            foreach ($unpaidPayments as $payment) {
                $payment->update([
                    'status' => Payment::STATUS_EXPIRED,
                    'archived_at' => now(),
                    'failure_reason' => 'Automatically archived after 24 hours',
                ]);
            }
        });

        $this->info("Successfully archived {$count} unpaid payments.");
        
        Log::info('Unpaid payments archived', [
            'count' => $count,
            'cutoff_time' => $cutoffTime->toIso8601String(),
        ]);
    }
}
