<?php

namespace App\Services;

use App\Models\AccessPoint;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class AccessPointHealthService
{
    public const WEBHOOK_CAPABILITY_VERDICT = 'webhook_not_safely_supported_using_current_setup';

    public const SYNC_HEARTBEAT_CACHE_KEY = 'omada:access-points:sync-heartbeat-at';

    public const RECONCILE_HEARTBEAT_CACHE_KEY = 'omada:access-points:reconcile-heartbeat-at';

    public function noteSyncHeartbeat(?Carbon $at = null): void
    {
        Cache::put(self::SYNC_HEARTBEAT_CACHE_KEY, ($at ?? now())->toIso8601String(), now()->addDay());
    }

    public function noteReconcileHeartbeat(?Carbon $at = null): void
    {
        Cache::put(self::RECONCILE_HEARTBEAT_CACHE_KEY, ($at ?? now())->toIso8601String(), now()->addDay());
    }

    public function webhookCapabilityVerdict(): string
    {
        return self::WEBHOOK_CAPABILITY_VERDICT;
    }

    public function applyControllerObservation(
        AccessPoint $accessPoint,
        array $device,
        Carbon $checkedAt,
        string $claimStatus,
        string $source = AccessPoint::STATUS_SOURCE_SYNC,
    ): void {
        $healthState = $this->resolveHealthState($device, $claimStatus);
        $rawStatus = $this->resolveRawStatus($device);
        $eventAt = $this->resolveObservationEventAt($device, $checkedAt);
        $metadata = $accessPoint->health_metadata ?? [];
        $previousState = $accessPoint->health_state;
        $previousControllerState = Arr::get($metadata, 'controller_observations.last_state');
        $previousConnectedStreak = (int) Arr::get($metadata, 'controller_observations.connected_streak', 0);

        $connectedStreak = $healthState === AccessPoint::HEALTH_STATE_CONNECTED
            ? ($previousControllerState === AccessPoint::HEALTH_STATE_CONNECTED ? $previousConnectedStreak + 1 : 1)
            : 0;

        $metadata['controller_observations'] = [
            'last_state' => $healthState,
            'raw_status' => $rawStatus,
            'checked_at' => $checkedAt->toIso8601String(),
            'event_at' => $eventAt->toIso8601String(),
            'connected_streak' => $connectedStreak,
            'source' => $source,
        ];
        $metadata['confidence'] = $this->deriveConfidence($healthState, $connectedStreak);
        unset($metadata['stale_reason']);

        if ($previousState && $previousState !== $healthState) {
            $accessPoint->last_health_mismatch_at = $checkedAt;
            $metadata['last_mismatch'] = [
                'previous_state' => $previousState,
                'current_state' => $healthState,
                'detected_at' => $checkedAt->toIso8601String(),
                'source' => $source,
            ];
        }

        $accessPoint->fill([
            'health_state' => $healthState,
            'health_checked_at' => $checkedAt,
            'status_source' => $source,
            'status_source_event_at' => $eventAt,
            'is_online' => $healthState === AccessPoint::HEALTH_STATE_CONNECTED,
            'last_seen_at' => $eventAt,
            'last_connected_at' => $healthState === AccessPoint::HEALTH_STATE_CONNECTED
                ? $checkedAt
                : $accessPoint->last_connected_at,
            'last_disconnected_at' => in_array($healthState, [
                AccessPoint::HEALTH_STATE_HEARTBEAT_MISSED,
                AccessPoint::HEALTH_STATE_DISCONNECTED,
            ], true)
                ? $checkedAt
                : $accessPoint->last_disconnected_at,
            'health_metadata' => $metadata,
        ]);

        if (! $accessPoint->first_confirmed_connected_at
            && $healthState === AccessPoint::HEALTH_STATE_CONNECTED
            && $connectedStreak >= 2) {
            $accessPoint->first_confirmed_connected_at = $checkedAt;
            $accessPoint->first_connected_at = $accessPoint->first_connected_at ?? $checkedAt;
            $metadata['confidence'] = 'confirmed';
            $metadata['controller_observations']['connected_streak'] = $connectedStreak;
            $accessPoint->health_metadata = $metadata;
        }
    }

    public function reconcileStaleHealth(?Carbon $checkedAt = null): int
    {
        $checkedAt ??= now();
        $threshold = $checkedAt->copy()->subSeconds($this->healthSignalMaxAgeSeconds());
        $updated = 0;

        AccessPoint::query()
            ->where(function ($query) use ($threshold): void {
                $query->where(function ($builder) use ($threshold): void {
                    $builder->whereNotNull('status_source_event_at')
                        ->where('status_source_event_at', '<', $threshold);
                })->orWhere(function ($builder) use ($threshold): void {
                    $builder->whereNull('status_source_event_at')
                        ->where(function ($nested) use ($threshold): void {
                            $nested->whereNull('health_checked_at')
                                ->orWhere('health_checked_at', '<', $threshold);
                        });
                });
            })
            ->where(function ($query): void {
                $query->whereNull('health_state')
                    ->orWhere('health_state', '!=', AccessPoint::HEALTH_STATE_STALE_UNKNOWN);
            })
            ->orderBy('id')
            ->chunkById(100, function ($accessPoints) use (&$updated, $checkedAt): void {
                foreach ($accessPoints as $accessPoint) {
                    $metadata = $accessPoint->health_metadata ?? [];
                    $previousState = $accessPoint->health_state;

                    $metadata['confidence'] = 'stale';
                    $metadata['stale_reason'] = 'health_signal_expired';
                    $metadata['reconcile'] = [
                        'checked_at' => $checkedAt->toIso8601String(),
                        'reason' => 'health_signal_expired',
                    ];

                    if ($previousState && $previousState !== AccessPoint::HEALTH_STATE_STALE_UNKNOWN) {
                        $metadata['last_mismatch'] = [
                            'previous_state' => $previousState,
                            'current_state' => AccessPoint::HEALTH_STATE_STALE_UNKNOWN,
                            'detected_at' => $checkedAt->toIso8601String(),
                            'source' => AccessPoint::STATUS_SOURCE_RECONCILE,
                        ];
                    }

                    $accessPoint->forceFill([
                        'health_state' => AccessPoint::HEALTH_STATE_STALE_UNKNOWN,
                        'health_checked_at' => $checkedAt,
                        'status_source' => AccessPoint::STATUS_SOURCE_RECONCILE,
                        'is_online' => false,
                        'last_health_mismatch_at' => $previousState && $previousState !== AccessPoint::HEALTH_STATE_STALE_UNKNOWN
                            ? $checkedAt
                            : $accessPoint->last_health_mismatch_at,
                        'health_metadata' => $metadata,
                    ])->save();

                    $updated++;
                }
            });

        return $updated;
    }

    public function runtimeHealth(): array
    {
        $totalAccessPoints = AccessPoint::query()->count();
        $staleUnknownCount = AccessPoint::query()
            ->where('health_state', AccessPoint::HEALTH_STATE_STALE_UNKNOWN)
            ->count();

        $syncHeartbeat = $this->parseHeartbeat(Cache::get(self::SYNC_HEARTBEAT_CACHE_KEY));
        $reconcileHeartbeat = $this->parseHeartbeat(Cache::get(self::RECONCILE_HEARTBEAT_CACHE_KEY));
        $degradedThreshold = now()->subSeconds($this->runtimeDegradedAfterSeconds());
        $syncDegraded = $totalAccessPoints > 0 && (! $syncHeartbeat || $syncHeartbeat->lt($degradedThreshold));
        $reconcileDegraded = $totalAccessPoints > 0 && (! $reconcileHeartbeat || $reconcileHeartbeat->lt($degradedThreshold));
        $staleThresholdCount = $totalAccessPoints === 0
            ? 0
            : max(3, (int) ceil($totalAccessPoints * 0.25));
        $staleInventoryDegraded = $staleUnknownCount >= $staleThresholdCount && $staleThresholdCount > 0;

        return [
            'webhook_capability_verdict' => $this->webhookCapabilityVerdict(),
            'sync_heartbeat_at' => $syncHeartbeat?->toDateTimeString(),
            'reconcile_heartbeat_at' => $reconcileHeartbeat?->toDateTimeString(),
            'stale_unknown_count' => $staleUnknownCount,
            'total_access_points' => $totalAccessPoints,
            'degraded' => $syncDegraded || $reconcileDegraded || $staleInventoryDegraded,
            'sync_degraded' => $syncDegraded,
            'reconcile_degraded' => $reconcileDegraded,
            'stale_inventory_degraded' => $staleInventoryDegraded,
        ];
    }

    public function present(AccessPoint $accessPoint): array
    {
        $state = $accessPoint->health_state ?: $this->legacyFallbackState($accessPoint);
        $signalAt = $accessPoint->status_source_event_at
            ?? $accessPoint->last_seen_at
            ?? $accessPoint->last_synced_at;
        $freshnessSeconds = $signalAt ? max(0, now()->diffInSeconds($signalAt)) : null;
        $isFresh = $freshnessSeconds !== null
            && $freshnessSeconds <= $this->healthSignalMaxAgeSeconds()
            && $state !== AccessPoint::HEALTH_STATE_STALE_UNKNOWN;

        return [
            'health_state' => $state,
            'health_label' => $this->labelForState($state),
            'health_checked_at' => optional($accessPoint->health_checked_at)?->toDateTimeString(),
            'status_source' => $accessPoint->status_source,
            'status_source_event_at' => optional($accessPoint->status_source_event_at)?->toDateTimeString(),
            'freshness_seconds' => $freshnessSeconds,
            'freshness_label' => $this->humanizeSeconds($freshnessSeconds),
            'is_fresh' => $isFresh,
            'health_confidence' => Arr::get(
                $accessPoint->health_metadata ?? [],
                'confidence',
                $state === AccessPoint::HEALTH_STATE_STALE_UNKNOWN ? 'stale' : 'confirmed'
            ),
            'last_connected_at' => optional($accessPoint->last_connected_at)?->toDateTimeString(),
            'first_confirmed_connected_at' => optional($accessPoint->first_confirmed_connected_at)?->toDateTimeString(),
            'last_disconnected_at' => optional($accessPoint->last_disconnected_at)?->toDateTimeString(),
            'last_health_mismatch_at' => optional($accessPoint->last_health_mismatch_at)?->toDateTimeString(),
            'health_warning' => $this->warningForState($state, $freshnessSeconds, $accessPoint->last_health_mismatch_at),
        ];
    }

    private function resolveHealthState(array $device, string $claimStatus): string
    {
        if ($claimStatus === AccessPoint::CLAIM_STATUS_PENDING) {
            return AccessPoint::HEALTH_STATE_PENDING;
        }

        $booleanStatus = $this->firstFilled($device, ['isOnline', 'connected']);
        if (is_bool($booleanStatus)) {
            return $booleanStatus ? AccessPoint::HEALTH_STATE_CONNECTED : AccessPoint::HEALTH_STATE_DISCONNECTED;
        }

        if (is_numeric($booleanStatus)) {
            return (int) $booleanStatus === 1
                ? AccessPoint::HEALTH_STATE_CONNECTED
                : AccessPoint::HEALTH_STATE_DISCONNECTED;
        }

        $numericStatus = $this->firstFilled($device, [
            'status',
            'statusCategory',
        ]);

        if (is_numeric($numericStatus)) {
            return match ((int) $numericStatus) {
                1 => AccessPoint::HEALTH_STATE_CONNECTED,
                2 => AccessPoint::HEALTH_STATE_PENDING,
                default => AccessPoint::HEALTH_STATE_DISCONNECTED,
            };
        }

        $rawStatus = $this->resolveRawStatus($device);

        if (in_array($rawStatus, ['heartbeat_missed', 'heartbeat missed', 'isolated'], true)) {
            return AccessPoint::HEALTH_STATE_HEARTBEAT_MISSED;
        }

        if (in_array($rawStatus, ['connected', 'online', 'normal', 'up'], true)) {
            return AccessPoint::HEALTH_STATE_CONNECTED;
        }

        return AccessPoint::HEALTH_STATE_DISCONNECTED;
    }

    private function resolveRawStatus(array $device): ?string
    {
        $status = $this->firstFilled($device, [
            'statusCategory',
            'status',
            'state',
            'connectionStatus',
        ]);

        if ($status === null || $status === '') {
            return null;
        }

        if (is_bool($status)) {
            return $status ? 'connected' : 'disconnected';
        }

        if (is_numeric($status)) {
            return match ((int) $status) {
                1 => 'connected',
                2 => 'pending',
                default => 'disconnected',
            };
        }

        $normalized = strtolower(trim((string) $status));

        return str_replace('-', '_', preg_replace('/\s+/', '_', $normalized) ?? $normalized);
    }

    private function resolveObservationEventAt(array $device, Carbon $checkedAt): Carbon
    {
        $value = $this->firstFilled($device, [
            'lastSeenAt',
            'lastSeen',
            'latestSeen',
            'lastSeenTime',
        ]);

        if ($value === null || $value === '') {
            return $checkedAt;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;

            if ($timestamp > 9999999999) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return Carbon::createFromTimestamp($timestamp);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return $checkedAt;
        }
    }

    private function deriveConfidence(string $healthState, int $connectedStreak): string
    {
        if ($healthState === AccessPoint::HEALTH_STATE_STALE_UNKNOWN) {
            return 'stale';
        }

        if ($healthState === AccessPoint::HEALTH_STATE_CONNECTED) {
            return $connectedStreak >= 2 ? 'confirmed' : 'observed';
        }

        return 'confirmed';
    }

    private function labelForState(?string $state): string
    {
        return match ($state) {
            AccessPoint::HEALTH_STATE_CONNECTED => 'Connected',
            AccessPoint::HEALTH_STATE_HEARTBEAT_MISSED => 'Heartbeat Missed',
            AccessPoint::HEALTH_STATE_DISCONNECTED => 'Disconnected',
            AccessPoint::HEALTH_STATE_PENDING => 'Pending',
            AccessPoint::HEALTH_STATE_STALE_UNKNOWN => 'Stale Unknown',
            default => 'Unknown',
        };
    }

    private function warningForState(?string $state, ?int $freshnessSeconds, mixed $lastMismatchAt): ?string
    {
        if ($state === AccessPoint::HEALTH_STATE_STALE_UNKNOWN) {
            return 'Controller health signal is stale. Do not treat this as live state.';
        }

        if ($lastMismatchAt) {
            return 'Recent health drift detected. Validate against a fresh controller sync before acting on this AP.';
        }

        if ($freshnessSeconds !== null && $freshnessSeconds > $this->healthSignalMaxAgeSeconds()) {
            return 'Health signal is older than the freshness window.';
        }

        return null;
    }

    private function legacyFallbackState(AccessPoint $accessPoint): string
    {
        if ($accessPoint->claim_status === AccessPoint::CLAIM_STATUS_PENDING) {
            return AccessPoint::HEALTH_STATE_PENDING;
        }

        return $accessPoint->is_online
            ? AccessPoint::HEALTH_STATE_CONNECTED
            : AccessPoint::HEALTH_STATE_DISCONNECTED;
    }

    private function humanizeSeconds(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            return (string) ceil($seconds / 60).'m';
        }

        return (string) ceil($seconds / 3600).'h';
    }

    private function parseHeartbeat(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function healthSignalMaxAgeSeconds(): int
    {
        return max(60, (int) config('omada.health_signal_max_age_seconds', 180));
    }

    private function runtimeDegradedAfterSeconds(): int
    {
        return max($this->healthSignalMaxAgeSeconds(), (int) config('omada.health_runtime_degraded_after_seconds', 300));
    }

    private function firstFilled(array $payload, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($payload, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
