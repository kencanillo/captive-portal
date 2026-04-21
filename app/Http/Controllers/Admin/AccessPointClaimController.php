<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessPointClaim;
use App\Services\AccessPointClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AccessPointClaimController extends Controller
{
    public function index(): Response
    {
        $claimService = app(AccessPointClaimService::class);

        return Inertia::render('Admin/AccessPointClaims/Index', [
            'syncHealth' => $claimService->inventoryHealth(),
            'claims' => AccessPointClaim::query()
                ->with([
                    'operator.user:id,name,email',
                    'site:id,name',
                    'reviewedBy:id,name,email',
                    'matchedAccessPoint.site:id,name',
                ])
                ->latest('claimed_at')
                ->latest()
                ->get()
                ->map(fn (AccessPointClaim $claim) => $this->transform($claim)),
        ]);
    }

    public function approve(Request $request, AccessPointClaim $accessPointClaim, AccessPointClaimService $claimService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $claimService->approve($accessPointClaim, $request->user(), $validated['review_notes'] ?? null);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.access-point-claims.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.access-point-claims.index')
            ->with('success', 'AP claim approved. Adoption is still gated until the operator executes it.');
    }

    public function deny(Request $request, AccessPointClaim $accessPointClaim, AccessPointClaimService $claimService): RedirectResponse
    {
        $validated = $request->validate([
            'denial_reason' => ['required', 'string', 'max:2000'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $claimService->deny(
                $accessPointClaim,
                $request->user(),
                $validated['denial_reason'],
                $validated['review_notes'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.access-point-claims.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.access-point-claims.index')
            ->with('success', 'AP claim denied.');
    }

    private function transform(AccessPointClaim $claim): array
    {
        return [
            'id' => $claim->id,
            'claim_status' => $claim->claim_status,
            'claim_match_status' => $claim->claim_match_status,
            'claimed_at' => optional($claim->claimed_at)?->toDateTimeString(),
            'reviewed_at' => optional($claim->reviewed_at)?->toDateTimeString(),
            'matched_at' => optional($claim->matched_at)?->toDateTimeString(),
            'adoption_attempted_at' => optional($claim->adoption_attempted_at)?->toDateTimeString(),
            'requested_serial_number' => $claim->requested_serial_number,
            'requested_mac_address' => $claim->requested_mac_address,
            'requested_ap_name' => $claim->requested_ap_name,
            'requires_re_review' => $claim->requires_re_review,
            'conflict_state' => $claim->conflict_state,
            'sync_freshness_checked_at' => optional($claim->sync_freshness_checked_at)?->toDateTimeString(),
            'match_snapshot' => $claim->match_snapshot,
            'review_notes' => $claim->review_notes,
            'denial_reason' => $claim->denial_reason,
            'failure_reason' => $claim->failure_reason,
            'adoption_result_metadata' => $claim->adoption_result_metadata,
            'operator' => $claim->operator ? [
                'id' => $claim->operator->id,
                'business_name' => $claim->operator->business_name,
                'user_name' => $claim->operator->user?->name,
                'user_email' => $claim->operator->user?->email,
            ] : null,
            'site' => $claim->site ? [
                'id' => $claim->site->id,
                'name' => $claim->site->name,
            ] : null,
            'reviewed_by' => $claim->reviewedBy ? [
                'id' => $claim->reviewedBy->id,
                'name' => $claim->reviewedBy->name,
                'email' => $claim->reviewedBy->email,
            ] : null,
            'matched_access_point' => $claim->matchedAccessPoint ? [
                'id' => $claim->matchedAccessPoint->id,
                'name' => $claim->matchedAccessPoint->name,
                'mac_address' => $claim->matchedAccessPoint->mac_address,
                'serial_number' => $claim->matchedAccessPoint->serial_number,
                'omada_device_id' => $claim->matchedAccessPoint->omada_device_id,
                'site_name' => $claim->matchedAccessPoint->site?->name,
                'claim_status' => $claim->matchedAccessPoint->claim_status,
                'adoption_state' => $claim->matchedAccessPoint->adoption_state,
            ] : null,
        ];
    }
}
