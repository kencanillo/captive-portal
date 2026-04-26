<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceTransferRequest;
use App\Services\DeviceTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class DeviceTransferRequestController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/TransferRequests/Index', [
            'transferRequests' => DeviceTransferRequest::query()
                ->with([
                    'client:id,name,phone_number',
                    'fromDevice:id,client_id,mac_address',
                    'activeWifiSession:id,client_id,mac_address,start_time,end_time,is_active',
                    'reviewedBy:id,name,email',
                ])
                ->latest('requested_at')
                ->get()
                ->map(fn (DeviceTransferRequest $transferRequest) => $this->transform($transferRequest)),
        ]);
    }

    public function approve(Request $request, DeviceTransferRequest $deviceTransferRequest, DeviceTransferService $deviceTransferService): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $deviceTransferService->approve($deviceTransferRequest, $request->user(), $validated['review_notes'] ?? null);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.transfer-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.transfer-requests.index')
            ->with('success', 'Device transfer executed successfully.');
    }

    public function deny(Request $request, DeviceTransferRequest $deviceTransferRequest, DeviceTransferService $deviceTransferService): RedirectResponse
    {
        $validated = $request->validate([
            'denial_reason' => ['required', 'string', 'max:2000'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $deviceTransferService->deny(
                $deviceTransferRequest,
                $request->user(),
                $validated['denial_reason'],
                $validated['review_notes'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.transfer-requests.index')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.transfer-requests.index')
            ->with('success', 'Device transfer request denied.');
    }

    private function transform(DeviceTransferRequest $transferRequest): array
    {
        return [
            'id' => $transferRequest->id,
            'status' => $transferRequest->status,
            'requested_at' => optional($transferRequest->requested_at)?->toDateTimeString(),
            'reviewed_at' => optional($transferRequest->reviewed_at)?->toDateTimeString(),
            'executed_at' => optional($transferRequest->executed_at)?->toDateTimeString(),
            'requested_mac_address' => $transferRequest->requested_mac_address,
            'requested_phone_number' => $transferRequest->requested_phone_number,
            'review_notes' => $transferRequest->review_notes,
            'denial_reason' => $transferRequest->denial_reason,
            'failure_reason' => $transferRequest->failure_reason,
            'metadata' => $transferRequest->metadata,
            'execution_metadata' => $transferRequest->execution_metadata,
            'client' => $transferRequest->client ? [
                'id' => $transferRequest->client->id,
                'name' => $transferRequest->client->name,
                'phone_number' => $transferRequest->client->phone_number,
            ] : null,
            'from_device' => $transferRequest->fromDevice ? [
                'id' => $transferRequest->fromDevice->id,
                'mac_address' => $transferRequest->fromDevice->mac_address,
            ] : null,
            'active_session' => $transferRequest->activeWifiSession ? [
                'id' => $transferRequest->activeWifiSession->id,
                'mac_address' => $transferRequest->activeWifiSession->mac_address,
                'is_active' => $transferRequest->activeWifiSession->is_active,
                'start_time' => optional($transferRequest->activeWifiSession->start_time)?->toDateTimeString(),
                'end_time' => optional($transferRequest->activeWifiSession->end_time)?->toDateTimeString(),
            ] : null,
            'reviewed_by' => $transferRequest->reviewedBy ? [
                'id' => $transferRequest->reviewedBy->id,
                'name' => $transferRequest->reviewedBy->name,
                'email' => $transferRequest->reviewedBy->email,
            ] : null,
        ];
    }
}
