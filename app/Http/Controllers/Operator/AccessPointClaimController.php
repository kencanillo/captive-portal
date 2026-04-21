<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AccessPointClaim;
use App\Services\AccessPointClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AccessPointClaimController extends Controller
{
    public function store(Request $request, AccessPointClaimService $claimService): RedirectResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'requested_serial_number' => ['nullable', 'string', 'max:255'],
            'requested_mac_address' => ['nullable', 'string', 'max:255'],
            'requested_ap_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $claim = $claimService->submit(
                $request->user()->loadMissing('operator')->operator,
                $validated,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('operator.devices.index')
                ->with('error', $exception->getMessage());
        }

        $message = $claim->wasRecentlyCreated
            ? 'AP claim submitted for admin review.'
            : 'Existing open AP claim reused. Duplicate requests are blocked.';

        return redirect()
            ->route('operator.devices.index')
            ->with('success', $message);
    }

    public function adopt(AccessPointClaim $accessPointClaim, Request $request, AccessPointClaimService $claimService): RedirectResponse
    {
        try {
            $claimService->adopt($accessPointClaim, $request->user()->loadMissing('operator')->operator);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('operator.devices.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('operator.devices.index')
            ->with('success', 'AP adoption request sent successfully.');
    }
}
