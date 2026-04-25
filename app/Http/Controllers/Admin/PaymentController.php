<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Payments', [
            'payments' => Payment::query()
                ->with([
                    'wifiSession.plan:id,name',
                    'wifiSession.site:id,name',
                    'wifiSession.accessPoint:id,name,mac_address',
                ])
                ->latest()
                ->paginate(20)
                ->withQueryString(),
        ]);
    }
}
