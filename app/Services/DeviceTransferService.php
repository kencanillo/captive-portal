<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientDevice;
use App\Models\ControllerSetting;
use App\Models\DeviceTransferRequest;
use App\Models\User;
use App\Models\WifiSession;
use App\Support\MacAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

class DeviceTransferService
{
    public function __construct(
        private readonly OmadaService $omadaService,
        private readonly WifiSessionAuthorizationService $wifiSessionAuthorizationService,
    ) {}

    public function createOrReuseFromPortalFlow(
        Client $client,
        ?WifiSession $activeSession,
        string $requestedMacAddress,
        ?string $requestedPhoneNumber,
        array $metadata = [],
    ): ?DeviceTransferRequest {
        if (! $activeSession || ! $this->sessionIsTransferEligible($activeSession)) {
            return null;
        }

        return DB::transaction(function () use ($client, $activeSession, $requestedMacAddress, $requestedPhoneNumber, $metadata) {
            /** @var WifiSession $lockedActiveSession */
            $lockedActiveSession = WifiSession::query()
                ->with(['client', 'clientDevice'])
                ->lockForUpdate()
                ->findOrFail($activeSession->id);

            if (! $this->sessionIsTransferEligible($lockedActiveSession)) {
                return null;
            }

            $existingOpenRequest = DeviceTransferRequest::query()
                ->where('active_wifi_session_id', $lockedActiveSession->id)
                ->where('status', DeviceTransferRequest::STATUS_PENDING_REVIEW)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existingOpenRequest) {
                return $existingOpenRequest;
            }

            $rateLimitKey = "device-transfer-request:create:client:{$client->id}";

            if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
                throw new RuntimeException('Device transfer review is temporarily locked because too many requests were submitted. Try again later.');
            }

            RateLimiter::hit($rateLimitKey, 1800);

            return DeviceTransferRequest::query()->create([
                'client_id' => $client->id,
                'active_wifi_session_id' => $lockedActiveSession->id,
                'from_client_device_id' => $lockedActiveSession->client_device_id,
                'requested_mac_address' => MacAddress::normalizeForStorage($requestedMacAddress) ?? strtolower($requestedMacAddress),
                'requested_phone_number' => $requestedPhoneNumber,
                'status' => DeviceTransferRequest::STATUS_PENDING_REVIEW,
                'requested_at' => now(),
                'metadata' => array_filter([
                    'requested_via' => 'portal',
                    'request_ip' => $metadata['client_ip'] ?? null,
                    'site_name' => $metadata['site_name'] ?? null,
                    'ap_mac' => $metadata['ap_mac'] ?? null,
                    'ap_name' => $metadata['ap_name'] ?? null,
                    'ssid_name' => $metadata['ssid_name'] ?? null,
                ], fn ($value) => $value !== null && $value !== ''),
            ]);
        });
    }

    public function approve(
        DeviceTransferRequest $transferRequest,
        User $admin,
        ?string $reviewNotes = null,
        array $credentialUpdates = [],
    ): DeviceTransferRequest {
        $resolvedRequest = DB::transaction(function () use ($transferRequest, $admin, $reviewNotes, $credentialUpdates) {
            /** @var DeviceTransferRequest $lockedRequest */
            $lockedRequest = DeviceTransferRequest::query()
                ->with([
                    'client.devices',
                    'fromDevice',
                    'activeWifiSession.site',
                    'activeWifiSession.accessPoint',
                    'activeWifiSession.plan',
                    'activeWifiSession.client',
                ])
                ->lockForUpdate()
                ->findOrFail($transferRequest->id);

            if ($lockedRequest->status !== DeviceTransferRequest::STATUS_PENDING_REVIEW) {
                throw new RuntimeException('Only pending transfer requests can be approved.');
            }

            $activeSession = $lockedRequest->activeWifiSession;

            if (! $activeSession || ! $this->sessionIsTransferEligible($activeSession)) {
                $this->markFailed(
                    $lockedRequest,
                    $admin,
                    'Transfer cannot proceed because the active entitlement is no longer valid.',
                    $reviewNotes
                );

                return $lockedRequest->refresh(['client', 'fromDevice', 'activeWifiSession', 'reviewedBy']);
            }

            $targetMacAddress = MacAddress::normalizeForStorage($lockedRequest->requested_mac_address) ?? strtolower($lockedRequest->requested_mac_address);
            $targetDevice = ClientDevice::query()
                ->whereRaw('LOWER(mac_address) = ?', [$targetMacAddress])
                ->lockForUpdate()
                ->first();

            if ($targetDevice && (int) $targetDevice->client_id !== (int) $lockedRequest->client_id) {
                $this->markFailed(
                    $lockedRequest,
                    $admin,
                    'Transfer target is already bound to another account.',
                    $reviewNotes,
                    ['target_device_id' => $targetDevice->id]
                );

                return $lockedRequest->refresh(['client', 'fromDevice', 'activeWifiSession', 'reviewedBy']);
            }

            $settings = ControllerSetting::query()->first();

            if (! $settings) {
                $this->markFailed(
                    $lockedRequest,
                    $admin,
                    'Omada controller settings are required before the old device can be deauthorized.',
                    $reviewNotes
                );

                return $lockedRequest->refresh(['client', 'fromDevice', 'activeWifiSession', 'reviewedBy']);
            }

            $originalSessionAttributes = [
                'mac_address' => $activeSession->mac_address,
                'client_device_id' => $activeSession->client_device_id,
            ];
            $originalClientAttributes = [
                'mac_address' => $lockedRequest->client->mac_address,
                'phone_number' => $lockedRequest->client->phone_number,
                'pin' => $lockedRequest->client->pin,
            ];
            $originalFromDeviceStatus = $lockedRequest->fromDevice?->status;
            $oldSessionSnapshot = $this->snapshotSession($activeSession);
            $deauthorizedOldDevice = false;

            try {
                $this->omadaService->deauthorizeClient($settings, $oldSessionSnapshot);
                $deauthorizedOldDevice = true;

                $targetDevice ??= ClientDevice::query()->create([
                    'client_id' => $lockedRequest->client_id,
                    'mac_address' => $targetMacAddress,
                    'status' => 'bound',
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]);

                $targetDevice->forceFill([
                    'status' => 'bound',
                    'first_seen_at' => $targetDevice->first_seen_at ?? now(),
                    'last_seen_at' => now(),
                ])->save();

                $activeSession->forceFill([
                    'mac_address' => $targetMacAddress,
                    'client_device_id' => $targetDevice->id,
                ])->save();

                $lockedRequest->client->forceFill([
                    // Compatibility only. Device ownership lives in client_devices.
                    'mac_address' => $targetMacAddress,
                    'last_connected_at' => now(),
                ])->save();

                $this->omadaService->authorizeClient($settings, $activeSession->fresh(['site', 'accessPoint', 'plan', 'client']));
                $this->wifiSessionAuthorizationService->markSessionAuthorized($activeSession, 'device_transfer');

                $changedCredentials = $this->applyCredentialUpdates($lockedRequest->client, $credentialUpdates);

                if ($lockedRequest->fromDevice) {
                    $lockedRequest->fromDevice->forceFill([
                        'status' => 'replaced',
                    ])->save();
                }

                $lockedRequest->forceFill([
                    'status' => DeviceTransferRequest::STATUS_EXECUTED,
                    'reviewed_by_user_id' => $admin->id,
                    'reviewed_at' => now(),
                    'executed_at' => now(),
                    'review_notes' => $reviewNotes,
                    'failure_reason' => null,
                    'denial_reason' => null,
                    'execution_metadata' => [
                        'from_mac_address' => $originalSessionAttributes['mac_address'],
                        'to_mac_address' => $targetMacAddress,
                        'target_client_device_id' => $targetDevice->id,
                        'remaining_seconds_at_execution' => now()->diffInSeconds($activeSession->end_time, false),
                        'deauthorized_old_device' => true,
                        'credentials_updated' => $changedCredentials !== [],
                        'credential_updates' => $changedCredentials,
                    ],
                ])->save();

                Log::info('Device transfer executed.', [
                    'device_transfer_request_id' => $lockedRequest->id,
                    'client_id' => $lockedRequest->client_id,
                    'wifi_session_id' => $activeSession->id,
                    'reviewed_by_user_id' => $admin->id,
                    'from_mac_address' => $originalSessionAttributes['mac_address'],
                    'to_mac_address' => $targetMacAddress,
                ]);

                return $lockedRequest->refresh(['client', 'fromDevice', 'activeWifiSession', 'reviewedBy']);
            } catch (Throwable $exception) {
                $oldDeviceRestoreAttempted = false;
                $oldDeviceRestoreSucceeded = null;

                if ($activeSession->mac_address !== $originalSessionAttributes['mac_address']
                    || (int) $activeSession->client_device_id !== (int) $originalSessionAttributes['client_device_id']) {
                    $activeSession->forceFill($originalSessionAttributes)->save();
                    $lockedRequest->client->forceFill($originalClientAttributes)->save();
                } elseif ($lockedRequest->client->phone_number !== $originalClientAttributes['phone_number']
                    || $lockedRequest->client->pin !== $originalClientAttributes['pin']) {
                    $lockedRequest->client->forceFill($originalClientAttributes)->save();
                }

                if ($lockedRequest->fromDevice && $lockedRequest->fromDevice->status !== $originalFromDeviceStatus) {
                    $lockedRequest->fromDevice->forceFill([
                        'status' => $originalFromDeviceStatus,
                    ])->save();
                }

                if ($deauthorizedOldDevice) {
                    $oldDeviceRestoreAttempted = true;

                    try {
                        $this->omadaService->authorizeClient($settings, $oldSessionSnapshot);
                        $oldDeviceRestoreSucceeded = true;
                    } catch (Throwable $reauthorizeException) {
                        $oldDeviceRestoreSucceeded = false;

                        Log::warning('Old device reauthorization failed during transfer rollback.', [
                            'device_transfer_request_id' => $lockedRequest->id,
                            'client_id' => $lockedRequest->client_id,
                            'error' => $reauthorizeException->getMessage(),
                        ]);
                    }
                }

                $failureReason = $exception->getMessage();

                if ($oldDeviceRestoreAttempted && $oldDeviceRestoreSucceeded === false) {
                    $failureReason .= ' Old device deauthorization could not be rolled back. Manual controller follow-up is required.';
                }

                $this->markFailed(
                    $lockedRequest,
                    $admin,
                    $failureReason,
                    $reviewNotes,
                    [
                        'from_mac_address' => $originalSessionAttributes['mac_address'],
                        'to_mac_address' => $targetMacAddress,
                        'deauthorized_old_device' => $deauthorizedOldDevice,
                        'old_device_restore_attempted' => $oldDeviceRestoreAttempted,
                        'old_device_restore_succeeded' => $oldDeviceRestoreSucceeded,
                        'controller_state_uncertain' => $oldDeviceRestoreAttempted && $oldDeviceRestoreSucceeded === false,
                    ]
                );

                return $lockedRequest->refresh(['client', 'fromDevice', 'activeWifiSession', 'reviewedBy']);
            }
        });

        if ($resolvedRequest->status === DeviceTransferRequest::STATUS_FAILED) {
            throw new RuntimeException($resolvedRequest->failure_reason ?? 'Device transfer failed.');
        }

        return $resolvedRequest;
    }

    public function deny(DeviceTransferRequest $transferRequest, User $admin, string $denialReason, ?string $reviewNotes = null): DeviceTransferRequest
    {
        return DB::transaction(function () use ($transferRequest, $admin, $denialReason, $reviewNotes) {
            /** @var DeviceTransferRequest $lockedRequest */
            $lockedRequest = DeviceTransferRequest::query()
                ->lockForUpdate()
                ->findOrFail($transferRequest->id);

            if ($lockedRequest->status !== DeviceTransferRequest::STATUS_PENDING_REVIEW) {
                throw new RuntimeException('Only pending transfer requests can be denied.');
            }

            $lockedRequest->forceFill([
                'status' => DeviceTransferRequest::STATUS_DENIED,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
                'review_notes' => $reviewNotes,
                'denial_reason' => $denialReason,
                'failure_reason' => null,
                'execution_metadata' => null,
            ])->save();

            return $lockedRequest->refresh(['client', 'fromDevice', 'activeWifiSession', 'reviewedBy']);
        });
    }

    public function toPublicPayload(?DeviceTransferRequest $transferRequest): ?array
    {
        if (! $transferRequest) {
            return null;
        }

        return [
            'status' => $transferRequest->status,
            'requested_at' => $transferRequest->requested_at?->toIso8601String(),
        ];
    }

    private function markFailed(
        DeviceTransferRequest $transferRequest,
        User $admin,
        string $failureReason,
        ?string $reviewNotes = null,
        array $executionMetadata = [],
    ): void {
        $transferRequest->forceFill([
            'status' => DeviceTransferRequest::STATUS_FAILED,
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
            'review_notes' => $reviewNotes,
            'failure_reason' => $failureReason,
            'execution_metadata' => $executionMetadata === [] ? null : $executionMetadata,
        ])->save();
    }

    private function snapshotSession(WifiSession $session): WifiSession
    {
        $snapshot = $session->replicate();
        $snapshot->exists = true;
        $snapshot->setRelations($session->getRelations());

        return $snapshot;
    }

    private function sessionIsTransferEligible(WifiSession $session): bool
    {
        return (bool) $session->is_active
            && $session->end_time !== null
            && $session->end_time->isFuture();
    }

    private function applyCredentialUpdates(Client $client, array $credentialUpdates): array
    {
        $updates = [];
        $changed = [];
        $phoneNumber = $credentialUpdates['phone_number'] ?? null;
        $pin = $credentialUpdates['pin'] ?? null;

        if (filled($phoneNumber) && $phoneNumber !== $client->phone_number) {
            $existingClient = Client::query()
                ->where('phone_number', $phoneNumber)
                ->whereKeyNot($client->id)
                ->lockForUpdate()
                ->first();

            if ($existingClient) {
                throw new RuntimeException('The requested phone number is already assigned to another client.');
            }

            $updates['phone_number'] = $phoneNumber;
            $changed[] = 'phone_number';
        }

        if (filled($pin)) {
            $updates['pin'] = Hash::make($pin);
            $changed[] = 'pin';
        }

        if ($updates === []) {
            return [];
        }

        $client->forceFill($updates)->save();

        return $changed;
    }
}
