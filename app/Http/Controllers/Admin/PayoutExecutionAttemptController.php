<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayoutExecutionAttempt;
use App\Services\OperatorPayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PayoutExecutionAttemptController extends Controller
{
    public function reconcile(Request $request, PayoutExecutionAttempt $payoutExecutionAttempt, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'provider_payload' => ['nullable', 'array'],
        ]);

        try {
            $payoutService->reconcileExecutionAttempt(
                $payoutExecutionAttempt,
                $request->user(),
                $validated['provider_payload'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout execution attempt reconciled.');
    }

    public function retry(Request $request, PayoutExecutionAttempt $payoutExecutionAttempt, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'provider' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $payoutService->retryExecutionAttempt(
                $payoutExecutionAttempt,
                $request->user(),
                $validated['reason'],
                $validated['notes'] ?? null,
                $validated['provider'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout execution attempt retried.');
    }

    public function markCompleted(Request $request, PayoutExecutionAttempt $payoutExecutionAttempt, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $payoutService->markExecutionCompleted(
                $payoutExecutionAttempt,
                $request->user(),
                $validated['reason'],
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout execution attempt marked completed.');
    }

    public function markTerminalFailed(Request $request, PayoutExecutionAttempt $payoutExecutionAttempt, OperatorPayoutService $payoutService): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $payoutService->markExecutionTerminalFailed(
                $payoutExecutionAttempt,
                $request->user(),
                $validated['reason'],
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.payout-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.payout-requests.index')
            ->with('success', 'Payout execution attempt marked terminal failed.');
    }
}
