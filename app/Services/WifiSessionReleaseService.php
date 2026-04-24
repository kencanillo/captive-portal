<?php

namespace App\Services;

use App\Exceptions\ReleaseOperationException;
use App\Jobs\ReleaseWifiAccessJob;
use App\Models\ControllerSetting;
use App\Models\User;
use App\Models\WifiSession;
use App\Support\Release\ReleaseOutcome;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WifiSessionReleaseService
{
    public const MAX_AUTOMATIC_ATTEMPTS = 3;

    public const IN_PROGRESS_STALE_AFTER_SECONDS = 180;

    public const JOB_HEARTBEAT_CACHE_KEY = 'wifi:release:job-heartbeat-at';

    public const RECONCILE_HEARTBEAT_CACHE_KEY = 'wifi:release:reconcile-heartbeat-at';

    private const RETRY_DELAYS_SECONDS = [
        1 => 30,
        2 => 120,
        3 => 300,
    ];

    public function __construct(
        private readonly OmadaService $omadaService,
        private readonly ReleaseOutcomeClassifier $releaseOutcomeClassifier,
    ) {
    }

    public function queueInitialRelease(WifiSession $session, string $path, array $context = []): WifiSession
    {
        return $this->queueRelease($session, $path, $context);
    }

    public function queueAdminRetry(WifiSession $session, User $admin, array $context = []): WifiSession
    {
        return $this->queueRelease($session, 'admin_retry', array_merge($context, [
            'triggered_by_user_id' => $admin->id,
            'triggered_by_name' => $admin->name,
        ]));
    }

    public function attemptRelease(int|WifiSession $session, string $path, array $context = []): WifiSession
    {
        $sessionId = $session instanceof WifiSession ? $session->id : $session;

        $prepared = DB::transaction(function () use ($sessionId, $path, $context): array {
            /** @var WifiSession $lockedSession */
            $lockedSession = WifiSession::query()
                ->with(['plan', 'client', 'site', 'accessPoint'])
                ->lockForUpdate()
                ->findOrFail($sessionId);

            if ($lockedSession->payment_status !== WifiSession::PAYMENT_STATUS_PAID) {
                return ['should_attempt' => false, 'session' => $lockedSession->refresh()];
            }

            if ($lockedSession->session_status === WifiSession::SESSION_STATUS_MERGED) {
                $this->markReleaseSucceeded($lockedSession, $path, $context, null);

                return ['should_attempt' => false, 'session' => $lockedSession->refresh()];
            }

            if ($lockedSession->session_status === WifiSession::SESSION_STATUS_ACTIVE
                && $lockedSession->is_active
                && $lockedSession->release_status === WifiSession::RELEASE_STATUS_SUCCEEDED) {
                return ['should_attempt' => false, 'session' => $lockedSession->refresh()];
            }

            if ($lockedSession->session_status === WifiSession::SESSION_STATUS_ACTIVE && $lockedSession->is_active) {
                $this->markReleaseSucceeded($lockedSession, $path, $context, null);

                return ['should_attempt' => false, 'session' => $lockedSession->refresh()];
            }

            if ($lockedSession->release_status === WifiSession::RELEASE_STATUS_IN_PROGRESS
                && ! $this->isStaleInProgress($lockedSession)) {
                return ['should_attempt' => false, 'session' => $lockedSession->refresh()];
            }

            $attemptStartedAt = now();
            $proposedStart = $lockedSession->start_time?->copy()
                ?? $this->plannedWindowFromMetadata($lockedSession)['start']
                ?? $attemptStartedAt->copy();
            $proposedEnd = $lockedSession->end_time?->copy()
                ?? $this->plannedWindowFromMetadata($lockedSession)['end']
                ?? $proposedStart->copy()->addMinutes($lockedSession->plan->duration_minutes);

            $lockedSession->forceFill([
                'session_status' => WifiSession::SESSION_STATUS_PAID,
                'start_time' => $proposedStart,
                'end_time' => $proposedEnd,
                'is_active' => false,
                'release_status' => WifiSession::RELEASE_STATUS_IN_PROGRESS,
                'release_outcome_type' => null,
                'release_attempt_count' => (int) $lockedSession->release_attempt_count + 1,
                'last_release_attempt_at' => $attemptStartedAt,
                'last_release_error' => null,
                'release_failure_reason' => null,
                'controller_state_uncertain' => false,
                'release_stuck_at' => $this->isStaleInProgress($lockedSession)
                    ? ($lockedSession->release_stuck_at ?? $attemptStartedAt)
                    : null,
                'release_metadata' => $this->mergeReleaseMetadata($lockedSession->release_metadata, [
                    'last_attempt' => [
                        'path' => $path,
                        'trigger_context' => $context,
                        'started_at' => $attemptStartedAt->toIso8601String(),
                        'attempt_number' => (int) $lockedSession->release_attempt_count + 1,
                        'planned_start_time' => $proposedStart->toIso8601String(),
                        'planned_end_time' => $proposedEnd->toIso8601String(),
                    ],
                ]),
            ])->save();

            $authorizationSnapshot = $this->buildAuthorizationSnapshot($lockedSession, $proposedStart, $proposedEnd);

            return [
                'should_attempt' => true,
                'session' => $lockedSession->refresh(['plan', 'client', 'site', 'accessPoint']),
                'authorization_snapshot' => $authorizationSnapshot,
                'proposed_start' => $proposedStart,
                'proposed_end' => $proposedEnd,
            ];
        });

        /** @var WifiSession $preparedSession */
        $preparedSession = $prepared['session'];

        if (! ($prepared['should_attempt'] ?? false)) {
            return $preparedSession;
        }

        try {
            $settings = ControllerSetting::query()->first();

            if (! $settings) {
                throw ReleaseOperationException::configuration(
                    'Omada controller settings are missing, so the paid client cannot be authorized.'
                );
            }

            $response = $this->omadaService->authorizeClient($settings, $prepared['authorization_snapshot']);

            return DB::transaction(function () use ($sessionId, $path, $context, $prepared, $response): WifiSession {
                /** @var WifiSession $lockedSession */
                $lockedSession = WifiSession::query()
                    ->with(['plan', 'client', 'site', 'accessPoint'])
                    ->lockForUpdate()
                    ->findOrFail($sessionId);

                if ($lockedSession->session_status === WifiSession::SESSION_STATUS_ACTIVE
                    && $lockedSession->is_active
                    && $lockedSession->release_status === WifiSession::RELEASE_STATUS_SUCCEEDED) {
                    return $lockedSession->refresh();
                }

                $this->ensureNoConflictingActiveEntitlement($lockedSession);

                $lockedSession->forceFill([
                    'start_time' => $prepared['proposed_start'],
                    'end_time' => $prepared['proposed_end'],
                    'is_active' => true,
                    'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
                    'release_status' => WifiSession::RELEASE_STATUS_SUCCEEDED,
                    'release_outcome_type' => WifiSession::RELEASE_OUTCOME_SUCCESS,
                    'last_release_error' => null,
                    'release_failure_reason' => null,
                    'controller_state_uncertain' => false,
                    'release_stuck_at' => null,
                    'released_at' => $lockedSession->released_at ?? now(),
                    'released_by_path' => $path,
                    'authorized_at' => $lockedSession->authorized_at ?? now(),
                    'deauthorized_at' => null,
                    'authorization_source' => $path,
                    'last_controller_seen_at' => now(),
                    'release_metadata' => $this->mergeReleaseMetadata($lockedSession->release_metadata, [
                        'last_success' => [
                            'path' => $path,
                            'trigger_context' => $context,
                            'released_at' => now()->toIso8601String(),
                            'response' => [
                                'errorCode' => $response['errorCode'] ?? null,
                                'msg' => $response['msg'] ?? null,
                            ],
                        ],
                    ]),
                ])->save();

                return $lockedSession->refresh();
            });
        } catch (Throwable $exception) {
            $attemptCount = (int) $preparedSession->fresh()->release_attempt_count;
            $outcome = $this->releaseOutcomeClassifier->classify($exception);

            if ($outcome->retryable && $attemptCount >= self::MAX_AUTOMATIC_ATTEMPTS) {
                $outcome = $this->releaseOutcomeClassifier->escalateToManualFollowup($outcome);
            }

            $failedSession = $this->persistReleaseOutcome(
                $sessionId,
                $outcome,
                $path,
                $context,
                $exception->getMessage(),
                [
                    'planned_start_time' => $prepared['proposed_start']->toIso8601String(),
                    'planned_end_time' => $prepared['proposed_end']->toIso8601String(),
                ]
            );

            Log::warning('WiFi session release failed.', [
                'wifi_session_id' => $failedSession->id,
                'client_id' => $failedSession->client_id,
                'path' => $path,
                'release_status' => $failedSession->release_status,
                'release_outcome_type' => $failedSession->release_outcome_type,
                'release_attempt_count' => $failedSession->release_attempt_count,
                'error' => $exception->getMessage(),
            ]);

            $this->scheduleAutomaticRetryIfNeeded($failedSession, $path, $context);

            return $failedSession;
        }
    }

    public function reconcileOutstandingSessions(int $limit = 100): int
    {
        $this->recordReconcileHeartbeat();

        $processed = 0;

        WifiSession::query()
            ->where('payment_status', WifiSession::PAYMENT_STATUS_PAID)
            ->where(function ($query): void {
                $query->whereIn('release_status', [
                    WifiSession::RELEASE_STATUS_UNCERTAIN,
                    WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
                ])->orWhere(function ($inProgress): void {
                    $inProgress->where('release_status', WifiSession::RELEASE_STATUS_IN_PROGRESS)
                        ->where(function ($stale): void {
                            $stale->whereNull('last_release_attempt_at')
                                ->orWhere('last_release_attempt_at', '<=', now()->subSeconds(self::IN_PROGRESS_STALE_AFTER_SECONDS));
                        });
                })->orWhere('controller_state_uncertain', true);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (WifiSession $session) use (&$processed): void {
                $this->reconcileSession($session, 'scheduled_reconcile');
                $processed++;
            });

        return $processed;
    }

    public function reconcileSession(int|WifiSession $session, string $path = 'manual_reconcile', array $context = []): WifiSession
    {
        $sessionId = $session instanceof WifiSession ? $session->id : $session;

        $snapshot = DB::transaction(function () use ($sessionId): array {
            /** @var WifiSession $lockedSession */
            $lockedSession = WifiSession::query()
                ->with(['plan', 'client', 'site', 'accessPoint'])
                ->lockForUpdate()
                ->findOrFail($sessionId);

            $staleInProgress = $this->isStaleInProgress($lockedSession);
            $needsReconcile = $lockedSession->payment_status === WifiSession::PAYMENT_STATUS_PAID
                && (
                    in_array($lockedSession->release_status, [
                        WifiSession::RELEASE_STATUS_UNCERTAIN,
                        WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
                    ], true)
                    || $staleInProgress
                    || $lockedSession->controller_state_uncertain
                );

            if (! $needsReconcile) {
                return ['should_reconcile' => false, 'session' => $lockedSession->refresh()];
            }

            $lockedSession->forceFill([
                'reconcile_attempt_count' => (int) $lockedSession->reconcile_attempt_count + 1,
                'last_reconciled_at' => now(),
                'release_stuck_at' => $staleInProgress
                    ? ($lockedSession->release_stuck_at ?? now())
                    : $lockedSession->release_stuck_at,
            ])->save();

            return [
                'should_reconcile' => true,
                'session' => $lockedSession->refresh(['plan', 'client', 'site', 'accessPoint']),
                'stale_in_progress' => $staleInProgress,
            ];
        });

        /** @var WifiSession $reconcileSession */
        $reconcileSession = $snapshot['session'];

        if (! ($snapshot['should_reconcile'] ?? false)) {
            return $reconcileSession;
        }

        try {
            $settings = ControllerSetting::query()->first();

            if (! $settings) {
                throw ReleaseOperationException::configuration(
                    'Omada controller settings are missing, so controller authorization state cannot be reconciled.'
                );
            }

            $inspection = $this->omadaService->inspectClientAuthorization($settings, $reconcileSession);

            if (($inspection['authorized'] ?? false) === true) {
                return DB::transaction(function () use ($sessionId, $path, $context, $inspection): WifiSession {
                    /** @var WifiSession $lockedSession */
                    $lockedSession = WifiSession::query()
                        ->with(['plan', 'client', 'site', 'accessPoint'])
                        ->lockForUpdate()
                        ->findOrFail($sessionId);

                    $this->ensureNoConflictingActiveEntitlement($lockedSession);
                    $this->restorePlannedWindowIfMissing($lockedSession);

                    $lockedSession->forceFill([
                        'is_active' => true,
                        'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
                        'release_status' => WifiSession::RELEASE_STATUS_SUCCEEDED,
                        'release_outcome_type' => WifiSession::RELEASE_OUTCOME_SUCCESS,
                        'controller_state_uncertain' => false,
                        'last_release_error' => null,
                        'release_failure_reason' => null,
                        'release_stuck_at' => null,
                        'released_at' => $lockedSession->released_at ?? now(),
                        'released_by_path' => 'reconcile_confirmed',
                        'authorized_at' => $lockedSession->authorized_at ?? now(),
                        'deauthorized_at' => null,
                        'authorization_source' => 'reconcile_confirmed',
                        'last_controller_seen_at' => now(),
                        'last_reconcile_result' => 'authorized_in_controller',
                        'release_metadata' => $this->mergeReleaseMetadata($lockedSession->release_metadata, [
                            'last_reconcile' => [
                                'path' => $path,
                                'trigger_context' => $context,
                                'checked_at' => now()->toIso8601String(),
                                'inspection' => $inspection,
                                'resolved_as' => 'success',
                            ],
                        ]),
                    ])->save();

                    return $lockedSession->refresh();
                });
            }

            $attemptCount = (int) $reconcileSession->reconcile_attempt_count;
            $outcome = $this->releaseOutcomeClassifier->retryableControllerFailure();

            if ($attemptCount >= self::MAX_AUTOMATIC_ATTEMPTS
                || $reconcileSession->release_status === WifiSession::RELEASE_STATUS_MANUAL_REQUIRED) {
                $outcome = $this->releaseOutcomeClassifier->escalateToManualFollowup($outcome);
            }

            $resolvedSession = $this->persistReleaseOutcome(
                $sessionId,
                $outcome,
                $path,
                $context,
                'Controller inspection confirmed the client is not currently authorized.',
                [
                    'inspection' => $inspection,
                    'stale_in_progress' => (bool) ($snapshot['stale_in_progress'] ?? false),
                ],
                'not_authorized_in_controller'
            );

            if ($outcome->retryable && ! $outcome->manualFollowupRequired) {
                $this->queueRelease($resolvedSession, 'reconcile_retry', [
                    'source_path' => $path,
                    'reconcile_reason' => 'not_authorized_in_controller',
                ]);
            }

            return $resolvedSession->refresh();
        } catch (Throwable $exception) {
            $attemptCount = (int) $reconcileSession->reconcile_attempt_count;
            $outcome = $this->releaseOutcomeClassifier->classify($exception);

            if ($outcome->retryable && $attemptCount >= self::MAX_AUTOMATIC_ATTEMPTS) {
                $outcome = $this->releaseOutcomeClassifier->escalateToManualFollowup($outcome);
            }

            return $this->persistReleaseOutcome(
                $sessionId,
                $outcome,
                $path,
                $context,
                $exception->getMessage(),
                ['stale_in_progress' => (bool) ($snapshot['stale_in_progress'] ?? false)],
                'reconcile_failed'
            );
        }
    }

    public function recordJobHeartbeat(): void
    {
        Cache::put(self::JOB_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addDay());
    }

    public function recordReconcileHeartbeat(): void
    {
        Cache::put(self::RECONCILE_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addDay());
    }

    public function runtimeHealth(): array
    {
        $outstandingReleaseCount = WifiSession::query()
            ->where('payment_status', WifiSession::PAYMENT_STATUS_PAID)
            ->where(function ($query): void {
                $query->whereIn('release_status', [
                    WifiSession::RELEASE_STATUS_PENDING,
                    WifiSession::RELEASE_STATUS_IN_PROGRESS,
                    WifiSession::RELEASE_STATUS_UNCERTAIN,
                    WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
                ])->orWhere('controller_state_uncertain', true);
            })
            ->count();

        $jobHeartbeat = $this->parseHeartbeat(Cache::get(self::JOB_HEARTBEAT_CACHE_KEY));
        $reconcileHeartbeat = $this->parseHeartbeat(Cache::get(self::RECONCILE_HEARTBEAT_CACHE_KEY));
        $degradedThreshold = now()->subSeconds($this->runtimeDegradedAfterSeconds());
        $workerDegraded = $outstandingReleaseCount > 0
            && (! $jobHeartbeat || $jobHeartbeat->lt($degradedThreshold));
        $reconcileDegraded = $outstandingReleaseCount > 0
            && (! $reconcileHeartbeat || $reconcileHeartbeat->lt($degradedThreshold));

        return [
            'outstanding_release_count' => $outstandingReleaseCount,
            'job_heartbeat_at' => $jobHeartbeat?->toDateTimeString(),
            'reconcile_heartbeat_at' => $reconcileHeartbeat?->toDateTimeString(),
            'degraded' => $workerDegraded || $reconcileDegraded,
            'worker_degraded' => $workerDegraded,
            'reconcile_degraded' => $reconcileDegraded,
        ];
    }

    private function queueRelease(WifiSession $session, string $path, array $context = []): WifiSession
    {
        $dispatch = false;

        $queuedSession = DB::transaction(function () use ($session, $path, $context, &$dispatch): WifiSession {
            /** @var WifiSession $lockedSession */
            $lockedSession = WifiSession::query()
                ->with(['plan', 'client', 'site', 'accessPoint'])
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($lockedSession->payment_status !== WifiSession::PAYMENT_STATUS_PAID) {
                throw new RuntimeException('Only paid sessions can be queued for WiFi release.');
            }

            if ($lockedSession->session_status === WifiSession::SESSION_STATUS_MERGED) {
                $this->markReleaseSucceeded($lockedSession, $path, $context, null);

                return $lockedSession->refresh();
            }

            if ($lockedSession->session_status === WifiSession::SESSION_STATUS_ACTIVE
                && $lockedSession->is_active
                && $lockedSession->release_status === WifiSession::RELEASE_STATUS_SUCCEEDED) {
                return $lockedSession->refresh();
            }

            if (in_array($lockedSession->release_status, [
                WifiSession::RELEASE_STATUS_PENDING,
                WifiSession::RELEASE_STATUS_IN_PROGRESS,
            ], true) && ! $this->isStaleInProgress($lockedSession)) {
                return $lockedSession->refresh();
            }

            $lockedSession->forceFill([
                'session_status' => WifiSession::SESSION_STATUS_PAID,
                'release_status' => WifiSession::RELEASE_STATUS_PENDING,
                'release_outcome_type' => null,
                'last_release_error' => null,
                'release_failure_reason' => null,
                'controller_state_uncertain' => false,
                'release_stuck_at' => $lockedSession->release_stuck_at,
                'release_metadata' => $this->mergeReleaseMetadata($lockedSession->release_metadata, [
                    'last_queue' => [
                        'path' => $path,
                        'trigger_context' => $context,
                        'queued_at' => now()->toIso8601String(),
                    ],
                ]),
            ])->save();

            $dispatch = true;

            return $lockedSession->refresh();
        });

        if ($dispatch) {
            ReleaseWifiAccessJob::dispatch($queuedSession->id, $path, $context)->afterCommit();
        }

        return $queuedSession;
    }

    private function scheduleAutomaticRetryIfNeeded(WifiSession $session, string $path, array $context = []): void
    {
        if (! in_array($session->release_status, [
            WifiSession::RELEASE_STATUS_FAILED,
            WifiSession::RELEASE_STATUS_UNCERTAIN,
        ], true)) {
            return;
        }

        $lastFailure = $session->release_metadata['last_failure'] ?? [];

        if (! (bool) ($lastFailure['retryable'] ?? false)) {
            return;
        }

        ReleaseWifiAccessJob::dispatch($session->id, 'automatic_retry', array_merge($context, [
            'previous_path' => $path,
            'retry_attempt' => (int) $session->release_attempt_count,
        ]))->delay(now()->addSeconds($this->retryDelayForAttempt((int) $session->release_attempt_count)))->afterCommit();
    }

    private function buildAuthorizationSnapshot(WifiSession $session, Carbon $start, Carbon $end): WifiSession
    {
        $snapshot = $session->replicate();
        $snapshot->exists = true;
        $snapshot->forceFill([
            'start_time' => $start,
            'end_time' => $end,
        ]);
        $snapshot->setRelations($session->getRelations());

        return $snapshot;
    }

    private function markReleaseSucceeded(WifiSession $session, string $path, array $context, ?array $response): void
    {
        $session->forceFill([
            'release_status' => WifiSession::RELEASE_STATUS_SUCCEEDED,
            'release_outcome_type' => WifiSession::RELEASE_OUTCOME_SUCCESS,
            'last_release_error' => null,
            'release_failure_reason' => null,
            'controller_state_uncertain' => false,
            'release_stuck_at' => null,
            'released_at' => $session->released_at ?? now(),
            'released_by_path' => $path,
            'authorized_at' => $session->authorized_at ?? now(),
            'deauthorized_at' => null,
            'authorization_source' => $path,
            'last_controller_seen_at' => now(),
            'release_metadata' => $this->mergeReleaseMetadata($session->release_metadata, [
                'last_success' => [
                    'path' => $path,
                    'trigger_context' => $context,
                    'released_at' => now()->toIso8601String(),
                    'response' => $response,
                ],
            ]),
        ])->save();
    }

    private function persistReleaseOutcome(
        int $sessionId,
        ReleaseOutcome $outcome,
        string $path,
        array $context,
        string $errorMessage,
        array $metadata = [],
        ?string $reconcileResult = null,
    ): WifiSession {
        return DB::transaction(function () use ($sessionId, $outcome, $path, $context, $errorMessage, $metadata, $reconcileResult): WifiSession {
            /** @var WifiSession $lockedSession */
            $lockedSession = WifiSession::query()
                ->with(['plan', 'client', 'site', 'accessPoint'])
                ->lockForUpdate()
                ->findOrFail($sessionId);

            $nextRetryAt = $outcome->retryable && ! $outcome->manualFollowupRequired
                ? now()->addSeconds($this->retryDelayForAttempt((int) $lockedSession->release_attempt_count))
                : null;

            $lockedSession->forceFill([
                'is_active' => false,
                'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
                'release_status' => $outcome->releaseStatus,
                'release_outcome_type' => $outcome->type,
                'last_release_error' => $errorMessage,
                'release_failure_reason' => $errorMessage,
                'controller_state_uncertain' => $outcome->controllerStateUncertain,
                'release_stuck_at' => ($metadata['stale_in_progress'] ?? false)
                    ? ($lockedSession->release_stuck_at ?? now())
                    : $lockedSession->release_stuck_at,
                'released_by_path' => null,
                'deauthorized_at' => $lockedSession->authorized_at ? now() : $lockedSession->deauthorized_at,
                'authorization_source' => $lockedSession->authorized_at ? $path : $lockedSession->authorization_source,
                'last_reconcile_result' => $reconcileResult ?? $lockedSession->last_reconcile_result,
                'release_metadata' => $this->mergeReleaseMetadata($lockedSession->release_metadata, [
                    'last_failure' => array_merge([
                        'path' => $path,
                        'trigger_context' => $context,
                        'failed_at' => now()->toIso8601String(),
                        'outcome_type' => $outcome->type,
                        'retryable' => $outcome->retryable,
                        'manual_followup_required' => $outcome->manualFollowupRequired,
                        'controller_state_uncertain' => $outcome->controllerStateUncertain,
                        'next_retry_at' => $nextRetryAt?->toIso8601String(),
                    ], $metadata),
                ]),
            ])->save();

            return $lockedSession->refresh();
        });
    }

    private function mergeReleaseMetadata(mixed $existing, array $overlay): array
    {
        $base = is_array($existing) ? $existing : [];

        return array_replace_recursive($base, $overlay);
    }

    private function retryDelayForAttempt(int $attemptCount): int
    {
        return self::RETRY_DELAYS_SECONDS[$attemptCount] ?? 300;
    }

    private function isStaleInProgress(WifiSession $session): bool
    {
        if ($session->release_status !== WifiSession::RELEASE_STATUS_IN_PROGRESS) {
            return false;
        }

        if (! $session->last_release_attempt_at) {
            return true;
        }

        return $session->last_release_attempt_at->lt(now()->subSeconds(self::IN_PROGRESS_STALE_AFTER_SECONDS));
    }

    private function ensureNoConflictingActiveEntitlement(WifiSession $session): void
    {
        $conflictingActiveSession = WifiSession::query()
            ->where('client_id', $session->client_id)
            ->where('id', '!=', $session->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($conflictingActiveSession) {
            throw ReleaseOperationException::policy(
                'Another device already has active internet access for this account.'
            );
        }
    }

    private function restorePlannedWindowIfMissing(WifiSession $session): void
    {
        $plannedWindow = $this->plannedWindowFromMetadata($session);

        if (! $session->start_time) {
            $session->start_time = $plannedWindow['start'] ?? now();
        }

        if (! $session->end_time) {
            $session->end_time = $plannedWindow['end']
                ?? $session->start_time->copy()->addMinutes($session->plan->duration_minutes);
        }
    }

    private function plannedWindowFromMetadata(WifiSession $session): array
    {
        $metadata = is_array($session->release_metadata) ? $session->release_metadata : [];
        $start = data_get($metadata, 'last_attempt.planned_start_time');
        $end = data_get($metadata, 'last_attempt.planned_end_time');

        return [
            'start' => $start ? Carbon::parse($start) : null,
            'end' => $end ? Carbon::parse($end) : null,
        ];
    }

    private function parseHeartbeat(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function runtimeDegradedAfterSeconds(): int
    {
        return max(60, (int) config('operations.release_runtime_degraded_after_seconds', 300));
    }
}
