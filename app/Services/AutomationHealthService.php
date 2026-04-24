<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class AutomationHealthService
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_STALE = 'stale';
    public const STATUS_MISSING = 'missing';

    public const SCHEDULER_HEARTBEAT_CACHE_KEY = 'ops:scheduler:heartbeat-at';
    public const QUEUE_WORKER_HEARTBEAT_CACHE_KEY = 'ops:queue-worker:heartbeat-at';

    public function __construct(
        private readonly AccessPointHealthService $accessPointHealthService,
        private readonly AccessPointBillingService $accessPointBillingService,
        private readonly WifiSessionReleaseService $wifiSessionReleaseService,
    ) {
    }

    public function recordSchedulerHeartbeat(?Carbon $at = null): void
    {
        Cache::put(self::SCHEDULER_HEARTBEAT_CACHE_KEY, ($at ?? now())->toIso8601String(), now()->addDay());
    }

    public function recordQueueWorkerHeartbeat(?Carbon $at = null): void
    {
        Cache::put(self::QUEUE_WORKER_HEARTBEAT_CACHE_KEY, ($at ?? now())->toIso8601String(), now()->addDay());
    }

    public function statusSummary(): array
    {
        $releaseRuntime = $this->wifiSessionReleaseService->runtimeHealth();
        $healthRuntime = $this->accessPointHealthService->runtimeHealth();
        $billingRuntime = $this->accessPointBillingService->runtimeHealth();

        $statuses = [
            $this->buildHeartbeatStatus(
                key: 'scheduler',
                label: 'Scheduler heartbeat',
                heartbeat: Cache::get(self::SCHEDULER_HEARTBEAT_CACHE_KEY),
                degradedAfterSeconds: $this->schedulerDegradedAfterSeconds(),
                required: true,
                summary: 'Expected every minute through Laravel scheduler.'
            ),
            $this->buildHeartbeatStatus(
                key: 'ap_sync',
                label: 'AP sync',
                heartbeat: $healthRuntime['sync_heartbeat_at'] ?? null,
                degradedAfterSeconds: $this->accessPointHealthRuntimeDegradedAfterSeconds(),
                required: true,
                summary: sprintf(
                    '%d stale_unknown APs out of %d tracked.',
                    (int) ($healthRuntime['stale_unknown_count'] ?? 0),
                    (int) ($healthRuntime['total_access_points'] ?? 0),
                )
            ),
            $this->buildHeartbeatStatus(
                key: 'ap_health_reconcile',
                label: 'AP health reconcile',
                heartbeat: $healthRuntime['reconcile_heartbeat_at'] ?? null,
                degradedAfterSeconds: $this->accessPointHealthRuntimeDegradedAfterSeconds(),
                required: true,
                summary: $healthRuntime['stale_inventory_degraded'] ?? false
                    ? 'Stale inventory threshold exceeded.'
                    : 'Reconcile expires stale AP health into stale_unknown.'
            ),
            $this->buildHeartbeatStatus(
                key: 'queue_worker',
                label: 'Queue worker heartbeat',
                heartbeat: Cache::get(self::QUEUE_WORKER_HEARTBEAT_CACHE_KEY),
                degradedAfterSeconds: $this->queueWorkerDegradedAfterSeconds(),
                required: true,
                summary: ((int) ($releaseRuntime['outstanding_release_count'] ?? 0)) > 0
                    ? sprintf('%d paid sessions still need internet activation.', (int) $releaseRuntime['outstanding_release_count'])
                    : 'Worker health is tracked explicitly with a queued heartbeat, not inferred from business traffic.'
            ),
            $this->buildHeartbeatStatus(
                key: 'release_reconcile',
                label: 'Access activation recovery',
                heartbeat: $releaseRuntime['reconcile_heartbeat_at'] ?? null,
                degradedAfterSeconds: $this->releaseRuntimeDegradedAfterSeconds(),
                required: ((int) ($releaseRuntime['outstanding_release_count'] ?? 0)) > 0,
                summary: ((int) ($releaseRuntime['outstanding_release_count'] ?? 0)) > 0
                    ? 'Needed to recover uncertain or stuck internet activation attempts.'
                    : 'No outstanding internet activation backlog to recover right now.'
            ),
            $this->buildHeartbeatStatus(
                key: 'billing_post',
                label: 'AP billing post',
                heartbeat: $billingRuntime['post_heartbeat_at'] ?? null,
                degradedAfterSeconds: $this->billingRuntimeDegradedAfterSeconds(),
                required: ((int) ($billingRuntime['candidate_count'] ?? 0)) > 0,
                summary: sprintf(
                    '%d billing candidates, %d blocked incidents, %d manual reviews.',
                    (int) ($billingRuntime['candidate_count'] ?? 0),
                    (int) ($billingRuntime['blocked_incident_count'] ?? 0),
                    (int) ($billingRuntime['manual_review_count'] ?? 0),
                )
            ),
        ];

        $warnings = collect($statuses)
            ->filter(fn (array $status) => $status['status'] !== self::STATUS_HEALTHY)
            ->map(fn (array $status) => "{$status['label']}: {$status['summary']}")
            ->values()
            ->all();

        if (($healthRuntime['stale_unknown_count'] ?? 0) > 0) {
            $warnings[] = sprintf(
                '%d access points are already in stale_unknown.',
                (int) $healthRuntime['stale_unknown_count']
            );
        }

        $overallStatus = $this->overallStatus($statuses);

        return [
            'overall_status' => $overallStatus,
            'overall_readiness' => $this->overallReadiness($statuses),
            'healthy' => $overallStatus === self::STATUS_HEALTHY,
            'degraded' => $overallStatus !== self::STATUS_HEALTHY,
            'statuses' => $statuses,
            'incidents' => $this->incidentsForStatuses($statuses),
            'warnings' => $warnings,
            'incident_counts' => [
                'outstanding_release_count' => (int) ($releaseRuntime['outstanding_release_count'] ?? 0),
                'stale_access_point_count' => (int) ($healthRuntime['stale_unknown_count'] ?? 0),
                'billing_blocked_incident_count' => (int) ($billingRuntime['blocked_incident_count'] ?? 0),
                'billing_manual_review_count' => (int) ($billingRuntime['manual_review_count'] ?? 0),
                'billing_blocked_by_automation_count' => (int) ($billingRuntime['blocked_by_automation_count'] ?? 0),
            ],
            'checked_at' => now()->toDateTimeString(),
        ];
    }

    private function buildHeartbeatStatus(
        string $key,
        string $label,
        mixed $heartbeat,
        int $degradedAfterSeconds,
        bool $required,
        string $summary,
    ): array {
        $heartbeatAt = $this->parseHeartbeat($heartbeat);
        $status = $this->resolveStatus($heartbeatAt, $degradedAfterSeconds, $required);

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'required' => $required,
            'severity' => $this->severityForStatus($status, $required),
            'blocking' => $this->severityForStatus($status, $required) === 'blocked',
            'incident_open' => $status !== self::STATUS_HEALTHY,
            'last_heartbeat_at' => $heartbeatAt?->toDateTimeString(),
            'stale_threshold_seconds' => $degradedAfterSeconds,
            'summary' => $this->statusSummaryText($status, $summary, $required),
        ];
    }

    private function resolveStatus(?Carbon $heartbeatAt, int $degradedAfterSeconds, bool $required): string
    {
        if (! $heartbeatAt) {
            return $required ? self::STATUS_MISSING : self::STATUS_HEALTHY;
        }

        $degradedThreshold = now()->subSeconds($degradedAfterSeconds);
        $staleThreshold = now()->subSeconds($degradedAfterSeconds * 2);

        if ($heartbeatAt->lt($staleThreshold)) {
            return self::STATUS_STALE;
        }

        if ($heartbeatAt->lt($degradedThreshold)) {
            return self::STATUS_DEGRADED;
        }

        return self::STATUS_HEALTHY;
    }

    private function statusSummaryText(string $status, string $summary, bool $required): string
    {
        return match ($status) {
            self::STATUS_MISSING => 'No heartbeat recorded. '.$summary,
            self::STATUS_STALE => 'Heartbeat is stale. '.$summary,
            self::STATUS_DEGRADED => 'Heartbeat is older than the runtime window. '.$summary,
            default => $required || ! str_starts_with($summary, 'No outstanding')
                ? $summary
                : 'Not currently observable because there is no outstanding work.',
        };
    }

    private function overallStatus(array $statuses): string
    {
        if (collect($statuses)->contains(fn (array $status) => in_array($status['status'], [self::STATUS_MISSING, self::STATUS_STALE], true))) {
            return self::STATUS_STALE;
        }

        if (collect($statuses)->contains(fn (array $status) => $status['status'] === self::STATUS_DEGRADED)) {
            return self::STATUS_DEGRADED;
        }

        return self::STATUS_HEALTHY;
    }

    private function incidentsForStatuses(array $statuses): array
    {
        return collect($statuses)
            ->filter(fn (array $status) => $status['incident_open'])
            ->map(fn (array $status) => [
                'key' => $status['key'],
                'label' => $status['label'],
                'severity' => $status['severity'],
                'summary' => $status['summary'],
                'incident_open' => true,
                'blocking' => $status['blocking'],
            ])
            ->values()
            ->all();
    }

    private function severityForStatus(string $status, bool $required): string
    {
        return match ($status) {
            self::STATUS_HEALTHY => 'healthy',
            self::STATUS_DEGRADED => $required ? 'degraded' : 'warning',
            self::STATUS_STALE, self::STATUS_MISSING => $required ? 'blocked' : 'warning',
            default => 'warning',
        };
    }

    private function overallReadiness(array $statuses): string
    {
        if (collect($statuses)->contains(fn (array $status) => $status['severity'] === 'blocked')) {
            return 'blocked';
        }

        if (collect($statuses)->contains(fn (array $status) => $status['severity'] === 'degraded')) {
            return 'degraded';
        }

        if (collect($statuses)->contains(fn (array $status) => $status['severity'] === 'warning')) {
            return 'warning';
        }

        return 'healthy';
    }

    private function parseHeartbeat(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function schedulerDegradedAfterSeconds(): int
    {
        return max(60, (int) config('operations.scheduler_heartbeat_degraded_after_seconds', 180));
    }

    private function accessPointHealthRuntimeDegradedAfterSeconds(): int
    {
        return max(
            (int) config('omada.health_signal_max_age_seconds', 180),
            (int) config('omada.health_runtime_degraded_after_seconds', 300)
        );
    }

    private function billingRuntimeDegradedAfterSeconds(): int
    {
        return max(60, (int) config('omada.billing_runtime_degraded_after_seconds', 300));
    }

    private function releaseRuntimeDegradedAfterSeconds(): int
    {
        return max(60, (int) config('operations.release_runtime_degraded_after_seconds', 300));
    }

    private function queueWorkerDegradedAfterSeconds(): int
    {
        return max(60, (int) config('operations.queue_worker_heartbeat_degraded_after_seconds', 180));
    }
}
