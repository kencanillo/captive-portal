<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\ControllerSetting;
use App\Models\Plan;
use App\Models\Site;
use App\Models\WifiSession;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $activeSessions = WifiSession::query()->where('is_active', true)->count();
        $totalRevenue = WifiSession::query()->where('payment_status', WifiSession::STATUS_PAID)->sum('amount_paid');
        $mostPopularPlan = Plan::query()
            ->withCount('wifiSessions')
            ->orderByDesc('wifi_sessions_count')
            ->first();

        $analytics = [
            'revenue_today' => WifiSession::query()
                ->where('payment_status', WifiSession::STATUS_PAID)
                ->whereDate('updated_at', now()->toDateString())
                ->sum('amount_paid'),
            'active_users_now' => $activeSessions,
            'total_sessions' => WifiSession::query()->count(),
            'tracked_access_points' => AccessPoint::query()->count(),
            'claimed_access_points' => AccessPoint::query()->where('claim_status', AccessPoint::CLAIM_STATUS_CLAIMED)->count(),
            'pending_access_points' => AccessPoint::query()->where('claim_status', AccessPoint::CLAIM_STATUS_PENDING)->count(),
            'sites_count' => Site::query()->count(),
            'unassigned_sessions' => WifiSession::query()->whereNull('access_point_id')->count(),
            'pause_ready_promos' => Plan::query()->where('supports_pause', true)->where('is_active', true)->count(),
            'anti_tethering_promos' => Plan::query()->where('enforce_no_tethering', true)->where('is_active', true)->count(),
            'controller_configured' => ControllerSetting::query()->whereNotNull('base_url')->exists(),
        ];

        $accessPoints = AccessPoint::query()
            ->with('site:id,name')
            ->withCount([
                'wifiSessions as active_sessions_count' => fn ($query) => $query->where('is_active', true),
                'wifiSessions as paid_sessions_count' => fn ($query) => $query->where('payment_status', WifiSession::STATUS_PAID),
            ])
            ->withSum([
                'wifiSessions as revenue_total' => fn ($query) => $query->where('payment_status', WifiSession::STATUS_PAID),
            ], 'amount_paid')
            ->withSum([
                'wifiSessions as revenue_today' => fn ($query) => $query
                    ->where('payment_status', WifiSession::STATUS_PAID)
                    ->whereDate('updated_at', now()->toDateString()),
            ], 'amount_paid')
            ->orderByDesc('revenue_total')
            ->get()
            ->map(fn (AccessPoint $accessPoint) => [
                'id' => $accessPoint->id,
                'name' => $accessPoint->name,
                'mac_address' => $accessPoint->mac_address,
                'site_name' => $accessPoint->site?->name,
                'is_online' => $accessPoint->is_online,
                'claim_status' => $accessPoint->claim_status,
                'custom_ssid' => $accessPoint->custom_ssid,
                'allow_client_pause' => $accessPoint->allow_client_pause,
                'block_tethering' => $accessPoint->block_tethering,
                'last_seen_at' => optional($accessPoint->last_seen_at)?->toDateTimeString(),
                'active_sessions_count' => $accessPoint->active_sessions_count,
                'paid_sessions_count' => $accessPoint->paid_sessions_count,
                'revenue_total' => number_format((float) ($accessPoint->revenue_total ?? 0), 2, '.', ''),
                'revenue_today' => number_format((float) ($accessPoint->revenue_today ?? 0), 2, '.', ''),
            ]);

        $siteSummary = Site::query()
            ->withCount('accessPoints')
            ->withCount([
                'wifiSessions as active_sessions_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->withSum([
                'wifiSessions as revenue_total' => fn ($query) => $query->where('payment_status', WifiSession::STATUS_PAID),
            ], 'amount_paid')
            ->orderByDesc('revenue_total')
            ->get()
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'access_points_count' => $site->access_points_count,
                'active_sessions_count' => $site->active_sessions_count,
                'revenue_total' => number_format((float) ($site->revenue_total ?? 0), 2, '.', ''),
            ]);

        return Inertia::render('Admin/Dashboard', [
            'activeSessionsCount' => $activeSessions,
            'totalRevenue' => number_format((float) $totalRevenue, 2, '.', ''),
            'mostPopularPlan' => $mostPopularPlan,
            'analytics' => $analytics,
            'controllerSettings' => ControllerSetting::query()->first()?->only([
                'controller_name',
                'base_url',
                'site_identifier',
                'site_name',
                'portal_base_url',
            ]),
            'accessPoints' => $accessPoints,
            'siteSummary' => $siteSummary,
        ]);
    }
}
