<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOperatorRegistrationRequest;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class OperatorRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/OperatorRegister');
    }

    public function store(StoreOperatorRegistrationRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $user = User::query()->create([
                'name' => $request->string('contact_name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => Hash::make($request->string('password')->toString()),
                'is_admin' => false,
            ]);

            Operator::query()->create([
                'user_id' => $user->id,
                'business_name' => $request->string('business_name')->toString(),
                'contact_name' => $request->string('contact_name')->toString(),
                'phone_number' => $request->string('phone_number')->toString(),
                'status' => Operator::STATUS_PENDING,
                'requested_site_name' => $request->string('site_name_request')->toString() ?: null,
                'payout_preferences' => [
                    'method' => $request->input('payout_method'),
                    'account_name' => $request->input('payout_account_name'),
                    'account_reference' => $request->input('payout_account_reference'),
                    'notes' => $request->input('payout_notes'),
                ],
            ]);
        });

        return redirect()
            ->route('admin.login')
            ->with('status', 'Operator registration submitted. Wait for admin approval before signing in.');
    }
}
