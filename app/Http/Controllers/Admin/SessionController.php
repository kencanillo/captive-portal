<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WifiSession;
use Inertia\Inertia;
use Inertia\Response;

class SessionController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', WifiSession::class);

        return Inertia::render('Admin/Sessions', [
            'sessions' => WifiSession::query()
                ->with([
                    'plan:id,name,price,duration_minutes',
                    'site:id,name',
                    'accessPoint:id,site_id,name,mac_address',
                ])
                ->latest()
                ->paginate(25)
                ->withQueryString(),
        ]);
    }
}
