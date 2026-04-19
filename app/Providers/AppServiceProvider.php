<?php

namespace App\Providers;

use App\Models\Plan;
use App\Models\Operator;
use App\Models\User;
use App\Models\WifiSession;
use App\Policies\PlanPolicy;
use App\Policies\WifiSessionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('portal-bootstrap', fn (Request $request) => [
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('portal-plans', fn (Request $request) => [
            Limit::perMinute(60)->by($request->ip()),
        ]);
        RateLimiter::for('portal-select-plan', fn (Request $request) => [
            Limit::perMinute(20)->by($request->ip()),
        ]);
        RateLimiter::for('portal-create-payment', fn (Request $request) => [
            Limit::perMinute(20)->by($request->ip()),
        ]);

        Gate::define('access-admin', fn (User $user) => (bool) $user->is_admin);
        Gate::define('access-operator-panel', fn (User $user) => ! $user->is_admin
            && $user->operator()->where('status', Operator::STATUS_APPROVED)->exists());
        Gate::policy(Plan::class, PlanPolicy::class);
        Gate::policy(WifiSession::class, WifiSessionPolicy::class);

        Vite::prefetch(concurrency: 3);
    }
}
