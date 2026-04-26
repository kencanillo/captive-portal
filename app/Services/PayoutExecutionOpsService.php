<?php

namespace App\Services;

use App\Models\PayoutExecutionAttempt;
use App\Models\PayoutRequest;
use App\Services\PayoutExecutions\PayoutExecutionAdapterFactory;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PayoutExecutionOpsService
{
    public const RECONCILE_HEARTBEAT_CACHE_KEY = 'payouts:execution-ops:reconcile-heartbeat-at';

    public function __construct(
        private readonly PayoutExecutionAdapterFactory $payoutExecutionAdapterFactory,
    ) {
    }

    public function noteReconcileHeartbeat(?Carbon $at = null): void
    {
        Cache::put(self::RECONCILE_HEARTBEAT_CACHE_KEY, ($at ?? now())->toIso8601String(), now()->addDay());
    }

    public function providerReadiness(?string $provider = null): array
    {
        try {
            return $this->payoutExecutionAdapterFactory->make($provider)->readiness();
        } catch (RuntimeException $exception) {
            return [
                'provider' => $provider ?: (string) config('payouts.execution_provider', 'manual'),
                'ready' => false,
                'summary' => $exception->getMessage(),
                'blocking_reason' => $exception->getMessage(),
                'details' => [],
            ];
        }
    }

    public function dispatchPreflight(PayoutRequest $payoutRequest, ?string $provider = null): array
    {
        $provider = $provider ?: (string) config('payouts.execution_provider', 'manual');
        $providerReadiness = $this->providerReadiness($provider);

        if (! $providerReadiness['ready']) {
            return [
                'ready' => false,
                'provider' => $provider,
                'blocking_reason' => $providerReadiness['blocking_reason'],
                'provider_readiness' => $providerReadiness,
                'destination' => null,
            ];
        }

        $destination = $this->payoutExecutionAdapterFactory->make($provider)->destinationPreflight($payoutRequest);

        if (! $destination['ready']) {
            return [
                'ready' => false,
                'provider' => $provider,
                'blocking_reason' => $destination['blocking_reason'],
                'provider_readiness' => $providerReadiness,
                'destination' => $destination,
            ];
        }

        return [
            'ready' => true,
            'provider' => $provider,
            'blocking_reason' => null,
            'provider_readiness' => $providerReadiness,
            'destination' => $destination,
        ];
    }

    public function runtimeHealth(): array
    {
        $provider = (string) config('payouts.execution_provider', 'manual');
        $providerReadiness = $this->providerReadiness($provider);
        $heartbeatAt = $this->parseHeartbeat(Cache::get(self::RECONCILE_HEARTBEAT_CACHE_KEY));
        $candidates = $this->backgroundReconcileCandidates();
        $retryableCount = $this->retryableAttemptCount();
        $outstandingCount = $candidates->count() + $retryableCount;
        $degradedThreshold = now()->subSeconds($this->reconcileHeartbeatDegradedAfterSeconds());
        $heartbeatState = 'healthy';

        if ($outstandingCount > 0) {
            if (! $heartbeatAt) {
                $heartbeatState = 'missing';
            } elseif ($heartbeatAt->lt($degradedThreshold->copy()->subSeconds($this->reconcileHeartbeatDegradedAfterSeconds()))) {
                $heartbeatState = 'stale';
            } elseif ($heartbeatAt->lt($degradedThreshold)) {
                $heartbeatState = 'degraded';
            }
        }

        return [
            'provider' => $provider,
            'provider_readiness' => $providerReadiness,
            'provider_mode' => data_get($providerReadiness, 'details.mode'),
            'live_execution_enabled' => (bool) data_get($providerReadiness, 'details.live_execution_enabled', false),
            'last_reconcile_heartbeat_at' => $heartbeatAt?->toDateTimeString(),
            'heartbeat_state' => $heartbeatState,
            'stale_or_ambiguous_count' => $candidates->count(),
            'retryable_failed_count' => $retryableCount,
            'background_reconcile_limit' => (int) config('payouts.execution.background_reconcile_limit', 25),
            'max_retry_attempts' => $this->maxRetryAttempts(),
            'degraded' => ! $providerReadiness['ready'] || ($outstandingCount > 0 && $heartbeatState !== 'healthy'),
            'blocking_reason' => ! $providerReadiness['ready']
                ? $providerReadiness['blocking_reason']
                : (($outstandingCount > 0 && in_array($heartbeatState, ['missing', 'stale'], true))
                    ? 'Payout provider reconcile automation is stale while execution attempts still need follow-up.'
                    : null),
        ];
    }

    public function backgroundReconcileCandidates(?int $limit = null): Collection
    {
        $candidates = PayoutRequest::query()
            ->with('latestExecutionAttempt.latestResolution')
            ->whereHas('latestExecutionAttempt', function ($query): void {
                $query->whereNotNull('provider_name')
                    ->where('provider_name', '!=', 'manual')
                    ->whereIn('execution_state', [
                        PayoutExecutionAttempt::STATE_DISPATCHED,
                        PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
                    ]);
            })
            ->latest('requested_at')
            ->get()
            ->map(fn (PayoutRequest $request) => $request->latestExecutionAttempt)
            ->filter(function (?PayoutExecutionAttempt $attempt): bool {
                if (! $attempt) {
                    return false;
                }

                return $attempt->isStale() || $attempt->execution_state === PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED;
            })
            ->values();

        if ($limit === null) {
            return $candidates;
        }

        return $candidates->take(max(1, $limit))->values();
    }

    public function retryableAttemptCount(): int
    {
        return PayoutRequest::query()
            ->with('latestExecutionAttempt')
            ->whereHas('latestExecutionAttempt', function ($query): void {
                $query->whereNotNull('provider_name')
                    ->where('provider_name', '!=', 'manual')
                    ->where('execution_state', PayoutExecutionAttempt::STATE_RETRYABLE_FAILED);
            })
            ->count();
    }

    public function maxRetryAttempts(): int
    {
        return max(0, (int) config('payouts.execution.max_retry_attempts', 2));
    }

    private function parseHeartbeat(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function reconcileHeartbeatDegradedAfterSeconds(): int
    {
        return max(60, (int) config('payouts.execution.reconcile_heartbeat_degraded_after_seconds', 300));
    }
}
