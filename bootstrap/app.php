<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_PREFIX
        );

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\PerformanceLogger::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'api/paymongo/webhook',
            'api/paymongo/payout-executions/*/callback',
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('ops:record-scheduler-heartbeat')->everyMinute()->withoutOverlapping();
        $schedule->command('ops:dispatch-queue-worker-heartbeat')->everyMinute()->withoutOverlapping();
        $schedule->command('wifi:expire-sessions')->everyMinute()->withoutOverlapping();
        $schedule->command('omada:reconcile-authorized-clients')->everyMinute()->withoutOverlapping();
        $schedule->command('wifi:reconcile-releases')->everyMinute()->withoutOverlapping();
        $schedule->command('omada:sync-access-points')->everyMinute()->withoutOverlapping();
        $schedule->command('omada:reconcile-access-point-health')->everyMinute()->withoutOverlapping();
        $schedule->command('billing:post-access-point-fees')->everyMinute()->withoutOverlapping();
        $schedule->command('payouts:reconcile-execution-attempts')->everyFiveMinutes()->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
