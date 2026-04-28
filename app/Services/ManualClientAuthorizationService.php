<?php

namespace App\Services;

use App\Models\AccessPoint;
use App\Models\Client;
use App\Models\ClientDevice;
use App\Models\ControllerSetting;
use App\Models\Operator;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use App\Support\MacAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ManualClientAuthorizationService
{
    public function __construct(
        private readonly OmadaService $omadaService,
        private readonly WifiSessionAuthorizationService $wifiSessionAuthorizationService,
        private readonly WifiSessionService $wifiSessionService,
    ) {}

    public function authorize(User $user, array $attributes): WifiSession
    {
        $plan = Plan::query()
            ->whereKey($attributes['plan_id'])
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            throw new RuntimeException('The selected plan is invalid or inactive.');
        }

        $operator = $user->is_admin ? null : $user->loadMissing('operator')->operator;
        if (! $user->is_admin && (! $operator || ! $operator->isApproved())) {
            throw new RuntimeException('Only admin and approved operator users can authorize clients.');
        }

        $existingSession = null;
        if (! empty($attributes['wifi_session_id'])) {
            $existingSession = WifiSession::query()
                ->with(['client', 'clientDevice'])
                ->find($attributes['wifi_session_id']);
        }

        if ($existingSession) {
            $context = $this->resolveNetworkContext($operator, [
                'access_point_id' => $existingSession->access_point_id,
                'site_id' => $existingSession->site_id,
                'ap_mac' => $existingSession->ap_mac,
                'ap_name' => $existingSession->ap_name,
                'ssid_name' => $existingSession->ssid_name,
                'radio_id' => $existingSession->radio_id,
            ]);

            $normalizedMac = MacAddress::normalizeForStorage($existingSession->mac_address);
            if ($normalizedMac === null) {
                throw new RuntimeException('A valid MAC address is required.');
            }

            $client = $existingSession->client ?? $this->findOrCreateClient($normalizedMac, $attributes);
            $clientDevice = $existingSession->clientDevice ?? $this->findOrCreateClientDevice($client, $normalizedMac);
        } else {
            $normalizedMac = MacAddress::normalizeForStorage((string) ($attributes['mac_address'] ?? ''));

            if ($normalizedMac === null) {
                throw new RuntimeException('A valid MAC address is required.');
            }

            $context = $this->resolveNetworkContext($operator, $attributes);
            $client = $this->findOrCreateClient($normalizedMac, $attributes);
            $clientDevice = $this->findOrCreateClientDevice($client, $normalizedMac);
        }

        Log::info('Manual authorization requested.', [
            'requested_by_user_id' => $user->id,
            'operator_id' => $operator?->id,
            'site_id' => $context['site_id'],
            'access_point_id' => $context['access_point_id'],
            'mac_address' => $normalizedMac,
            'plan_id' => $plan->id,
        ]);

        return DB::transaction(function () use ($user, $operator, $plan, $context, $normalizedMac, $client, $clientDevice, $attributes, $existingSession): WifiSession {
            $existingActive = WifiSession::query()
                ->whereRaw('LOWER(mac_address) = ?', [$normalizedMac])
                ->where('is_active', true)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existingActive && ((int) $existingActive->id !== (int) ($existingSession?->id))) {
                $this->wifiSessionService->expireSession($existingActive, ControllerSetting::query()->first());
            }

            $startTime = now();
            $endTime = $startTime->copy()->addMinutes($plan->duration_minutes);

            if ($existingSession) {
                $existingSession->forceFill([
                    'client_id' => $client->id,
                    'client_device_id' => $clientDevice->id,
                    'mac_address' => $normalizedMac,
                    'plan_id' => $plan->id,
                    'site_id' => $context['site_id'],
                    'access_point_id' => $context['access_point_id'],
                    'ap_mac' => $context['ap_mac'],
                    'ap_name' => $context['ap_name'],
                    'ssid_name' => $context['ssid_name'],
                    'radio_id' => $context['radio_id'],
                    'amount_paid' => $plan->price,
                    'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
                    'session_status' => WifiSession::SESSION_STATUS_PAID,
                    'release_status' => WifiSession::RELEASE_STATUS_IN_PROGRESS,
                    'source' => $operator ? 'manual_operator' : 'manual_admin',
                    'authorized_by_user_id' => $user->id,
                    'operator_id' => $operator?->id,
                    'authorization_note' => $attributes['note'] ?? null,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'is_active' => false,
                    'release_metadata' => array_merge($existingSession->release_metadata ?? [], [
                        'manual_authorization' => true,
                        'manual_authorized_by_role' => $operator ? 'operator' : 'admin',
                        'manual_authorized_from_existing_session' => true,
                    ]),
                ])->save();

                $session = $existingSession->refresh();
            } else {
                $session = WifiSession::query()->create([
                    'client_id' => $client->id,
                    'client_device_id' => $clientDevice->id,
                    'mac_address' => $normalizedMac,
                    'plan_id' => $plan->id,
                    'site_id' => $context['site_id'],
                    'access_point_id' => $context['access_point_id'],
                    'ap_mac' => $context['ap_mac'],
                    'ap_name' => $context['ap_name'],
                    'ssid_name' => $context['ssid_name'],
                    'radio_id' => $context['radio_id'],
                    'amount_paid' => $plan->price,
                    'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
                    'session_status' => WifiSession::SESSION_STATUS_PAID,
                    'release_status' => WifiSession::RELEASE_STATUS_IN_PROGRESS,
                    'source' => $operator ? 'manual_operator' : 'manual_admin',
                    'authorized_by_user_id' => $user->id,
                    'operator_id' => $operator?->id,
                    'authorization_note' => $attributes['note'] ?? null,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'is_active' => false,
                    'release_metadata' => [
                        'manual_authorization' => true,
                        'manual_authorized_by_role' => $operator ? 'operator' : 'admin',
                    ],
                ]);
            }

            Payment::query()
                ->where('wifi_session_id', $session->id)
                ->where('provider', Payment::PROVIDER_PAYMONGO)
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_AWAITING_PAYMENT])
                ->update([
                    'status' => Payment::STATUS_CANCELED,
                    'failure_reason' => 'Superseded by manual authorization.',
                ]);

            Payment::query()->create([
                'wifi_session_id' => $session->id,
                'created_by_user_id' => $user->id,
                'operator_id' => $operator?->id,
                'provider' => Payment::PROVIDER_MANUAL,
                'payment_flow' => 'manual_authorization',
                'reference_id' => 'manual-'.$session->id.'-'.now()->format('YmdHis'),
                'status' => $operator ? Payment::STATUS_CASH_COLLECTED : Payment::STATUS_PAID,
                'amount' => $plan->price,
                'currency' => 'PHP',
                'paid_at' => now(),
                'raw_response' => [
                    'note' => $attributes['note'] ?? null,
                    'manual_authorization' => true,
                ],
            ]);

            return $this->attemptOmadaAuthorization($session, $user);
        });
    }

    public function retry(WifiSession $session, User $user): WifiSession
    {
        if (! in_array($session->source, ['manual_admin', 'manual_operator'], true)) {
            throw new RuntimeException('Only manual sessions can be retried through this endpoint.');
        }

        return $this->attemptOmadaAuthorization($session, $user);
    }

    private function attemptOmadaAuthorization(WifiSession $session, User $user): WifiSession
    {
        $settings = ControllerSetting::query()->first();

        if (! $settings) {
            $session->forceFill([
                'release_status' => WifiSession::RELEASE_STATUS_FAILED,
                'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
                'release_failure_reason' => 'Omada controller settings are missing.',
                'last_release_error' => 'Omada controller settings are missing.',
            ])->save();

            return $session->refresh();
        }

        try {
            $response = $this->omadaService->authorizeClientForManualSession($settings, $session->fresh(['site']));

            $session->forceFill([
                'release_status' => WifiSession::RELEASE_STATUS_SUCCEEDED,
                'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
                'is_active' => true,
                'release_failure_reason' => null,
                'last_release_error' => null,
                'released_at' => now(),
                'released_by_path' => 'manual_authorization',
                'release_metadata' => array_merge($session->release_metadata ?? [], [
                    'manual_omada_response' => $response,
                    'last_manual_authorized_by_user_id' => $user->id,
                ]),
            ])->save();

            $this->wifiSessionAuthorizationService->markSessionAuthorized($session, $session->source);

            Log::info('Omada authorization succeeded for manual session.', [
                'wifi_session_id' => $session->id,
                'source' => $session->source,
            ]);
        } catch (Throwable $exception) {
            $session->forceFill([
                'release_status' => WifiSession::RELEASE_STATUS_FAILED,
                'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
                'release_failure_reason' => $exception->getMessage(),
                'last_release_error' => $exception->getMessage(),
                'release_metadata' => array_merge($session->release_metadata ?? [], [
                    'manual_omada_error' => $exception->getMessage(),
                    'last_manual_authorized_by_user_id' => $user->id,
                ]),
            ])->save();

            Log::warning('Omada authorization failed for manual session.', [
                'wifi_session_id' => $session->id,
                'source' => $session->source,
                'error' => $exception->getMessage(),
            ]);
        }

        return $session->fresh();
    }

    private function resolveNetworkContext(?Operator $operator, array $attributes): array
    {
        $accessPoint = null;
        $site = null;

        if (! empty($attributes['access_point_id'])) {
            $accessPoint = AccessPoint::query()
                ->when($operator, fn ($query) => $query->forOperator($operator))
                ->find($attributes['access_point_id']);
        }

        if (! $accessPoint && ! empty($attributes['site_id'])) {
            $site = Site::query()
                ->when($operator, fn ($query) => $query->where('operator_id', $operator->id))
                ->find($attributes['site_id']);
        }

        if ($operator && ! $accessPoint && ! $site) {
            Log::warning('Manual authorization blocked due to ownership scope.', [
                'operator_id' => $operator->id,
                'site_id' => $attributes['site_id'] ?? null,
                'access_point_id' => $attributes['access_point_id'] ?? null,
            ]);

            throw new RuntimeException('You can only authorize clients connected to your assigned site or access point.');
        }

        $site ??= $accessPoint?->site;

        return [
            'site_id' => $site?->id,
            'access_point_id' => $accessPoint?->id,
            'ap_mac' => $accessPoint?->mac_address ?? MacAddress::normalizeForStorage($attributes['ap_mac'] ?? null),
            'ap_name' => $accessPoint?->name ?? ($attributes['ap_name'] ?? null),
            'ssid_name' => $attributes['ssid_name'] ?? null,
            'radio_id' => isset($attributes['radio_id']) ? (int) $attributes['radio_id'] : null,
        ];
    }

    private function findOrCreateClient(string $macAddress, array $attributes): Client
    {
        $client = Client::findByMacAddress($macAddress);

        if ($client) {
            $client->forceFill([
                'name' => $attributes['client_name'] ?? $client->name,
                'phone_number' => $attributes['phone'] ?? $client->phone_number,
                'mac_address' => $macAddress,
                'last_connected_at' => now(),
            ])->save();

            return $client->refresh();
        }

        return Client::query()->create([
            'name' => $attributes['client_name'] ?? 'Walk-in Client',
            'phone_number' => $attributes['phone'] ?? 'N/A',
            'pin' => bcrypt('0000'),
            'mac_address' => $macAddress,
            'last_connected_at' => now(),
        ]);
    }

    private function findOrCreateClientDevice(Client $client, string $macAddress): ClientDevice
    {
        return ClientDevice::query()->firstOrCreate(
            [
                'client_id' => $client->id,
                'mac_address' => $macAddress,
            ],
            [
                'status' => 'active',
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]
        );
    }
}
