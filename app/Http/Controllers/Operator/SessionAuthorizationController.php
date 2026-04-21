<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\WifiSession;
use App\Services\WifiSessionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SessionAuthorizationController extends Controller
{
    public function __invoke(Request $request, WifiSession $session, WifiSessionService $wifiSessionService): Response
    {
        // Verify session belongs to operator's assigned sites
        $operator = request()->user()->loadMissing('operator.sites')->operator;
        
        if (! $operator) {
            return Inertia::location('/dashboard')->with('error', 'Operator not found');
        }

        // Check if session is associated with operator's sites
        $sessionSites = $session->site ? [$session->site->id] : [];
        $operatorSiteIds = $operator->sites()->pluck('id')->toArray();

        if (! array_intersect($sessionSites, $operatorSiteIds)) {
            return Inertia::location('/dashboard')->with('error', 'Session not associated with your assigned sites');
        }

        // Verify session is paid but not active
        if ($session->payment_status !== WifiSession::PAYMENT_STATUS_PAID) {
            return Inertia::location('/dashboard')->with('error', 'Session must be paid before authorization');
        }

        if ($session->is_active && $session->session_status === WifiSession::SESSION_STATUS_ACTIVE) {
            return Inertia::location('/dashboard')->with('error', 'Session is already active');
        }

        try {
            $wifiSessionService->activateSession($session);
            
            return Inertia::location('/dashboard')->with('success', 'Session authorized successfully');
        } catch (\Throwable $exception) {
            return Inertia::location('/dashboard')->with('error', 'Failed to authorize session: ' . $exception->getMessage());
        }
    }
}
