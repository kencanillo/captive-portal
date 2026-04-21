<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Archive unpaid payments older than 24 hours - runs daily at 2 AM
        $schedule->command('app:archive-unpaid-payments')
            ->daily()
            ->at('02:00')
            ->description('Archive unpaid payments older than 24 hours');

        // Expire WiFi sessions - runs every 5 minutes
        $schedule->command('wifi:expire-sessions')
            ->everyFiveMinutes()
            ->description('Expire expired WiFi sessions and deauthorize clients');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): array
    {
        return [
            \App\Console\Commands\ArchiveUnpaidPayments::class,
            \App\Console\Commands\ExpireWifiSessionsCommand::class,
            \App\Console\Commands\SyncOperatorCredentialsCommand::class,
        ];
    }
}
