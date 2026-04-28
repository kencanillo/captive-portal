<?php

namespace App\Providers;

use App\Models\Plan;
use App\Models\Operator;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use App\Models\AccessPoint;
use App\Policies\PlanPolicy;
use App\Policies\WifiSessionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('operator-access-point-claims', fn (Request $request) => [
            Limit::perMinute(10)->by(optional($request->user())->id ?: $request->ip()),
        ]);

        Gate::define('access-admin', fn (User $user) => (bool) $user->is_admin);
        Gate::define('access-operator-panel', fn (User $user) => ! $user->is_admin
            && $user->operator()->where('status', Operator::STATUS_APPROVED)->exists());
        Gate::define('manual-authorize-client', function (User $user, ?int $siteId = null, ?int $accessPointId = null): bool {
            if ((bool) $user->is_admin) {
                return true;
            }

            $operator = $user->operator;

            if (! $operator || $operator->status !== Operator::STATUS_APPROVED) {
                return false;
            }

            if ($accessPointId) {
                return AccessPoint::query()
                    ->forOperator($operator)
                    ->whereKey($accessPointId)
                    ->exists();
            }

            if ($siteId) {
                return Site::query()
                    ->where('operator_id', $operator->id)
                    ->whereKey($siteId)
                    ->exists();
            }

            return false;
        });
        Gate::policy(Plan::class, PlanPolicy::class);
        Gate::policy(WifiSession::class, WifiSessionPolicy::class);
    }
}
