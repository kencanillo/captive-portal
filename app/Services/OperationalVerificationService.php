<?php

namespace App\Services;

use App\Models\ControllerSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

class OperationalVerificationService
{
    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    public const CACHE_KEY = 'ops:verification:last-result';

    public function __construct(
        private readonly AutomationHealthService $automationHealthService,
        private readonly MigrationPortabilityService $migrationPortabilityService,
        private readonly OmadaService $omadaService,
    ) {
    }

    public function verify(?User $actor = null): array
    {
        $settings = ControllerSetting::query()->first();
        $automation = $this->automationHealthService->statusSummary();

        $checks = [
            $this->controllerConnectivityCheck($settings),
            $this->automationCheck('queue_worker_freshness', 'Queue worker freshness', $this->automationStatusFor($automation, 'queue_worker')),
            $this->automationCheck('ap_sync_freshness', 'AP sync freshness', $this->automationStatusFor($automation, 'ap_sync')),
            $this->automationCheck('ap_health_reconcile_freshness', 'AP health reconcile freshness', $this->automationStatusFor($automation, 'ap_health_reconcile')),
            $this->automationCheck('release_reconcile_freshness', 'Access activation recovery freshness', $this->automationStatusFor($automation, 'release_reconcile')),
            $this->automationCheck('billing_post_freshness', 'Billing post freshness', $this->automationStatusFor($automation, 'billing_post')),
            $this->automationCheck('scheduler_activity', 'Scheduler activity', $this->automationStatusFor($automation, 'scheduler')),
            $this->migrationPortabilityCheck(),
        ];

        $overallStatus = collect($checks)->contains(fn (array $check) => $check['status'] === self::STATUS_FAIL)
            ? self::STATUS_FAIL
            : (collect($checks)->contains(fn (array $check) => $check['status'] === self::STATUS_WARN)
                ? self::STATUS_WARN
                : self::STATUS_PASS);

        $result = [
            'performed_at' => now()->toDateTimeString(),
            'performed_by' => $actor ? [
                'id' => $actor->id,
                'name' => $actor->name,
            ] : null,
            'overall_status' => $overallStatus,
            'checks' => $checks,
            'automation_overall_status' => $automation['overall_status'],
            'warnings' => $automation['warnings'],
            'incident_counts' => $automation['incident_counts'],
        ];

        Cache::put(self::CACHE_KEY, $result, now()->addSeconds($this->verificationCacheTtlSeconds()));

        return $result;
    }

    public function latestResult(): ?array
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_array($value) ? $value : null;
    }

    private function controllerConnectivityCheck(?ControllerSetting $settings): array
    {
        if (! $settings) {
            return [
                'key' => 'controller_connectivity',
                'label' => 'Controller connectivity',
                'status' => self::STATUS_FAIL,
                'summary' => 'Controller settings are missing.',
            ];
        }

        if (! $settings->canTestConnection()) {
            return [
                'key' => 'controller_connectivity',
                'label' => 'Controller connectivity',
                'status' => self::STATUS_FAIL,
                'summary' => 'Controller credentials are incomplete, so connectivity cannot be verified.',
            ];
        }

        try {
            $info = $this->omadaService->testConnection($settings);

            return [
                'key' => 'controller_connectivity',
                'label' => 'Controller connectivity',
                'status' => self::STATUS_PASS,
                'summary' => sprintf(
                    'Reachable controller: %s (%s).',
                    $info['controller_name'] ?? 'Omada controller',
                    $info['version'] ?? 'unknown version'
                ),
            ];
        } catch (Throwable $exception) {
            return [
                'key' => 'controller_connectivity',
                'label' => 'Controller connectivity',
                'status' => self::STATUS_FAIL,
                'summary' => $exception->getMessage(),
            ];
        }
    }

    private function automationCheck(string $key, string $label, ?array $automationStatus): array
    {
        if (! $automationStatus) {
            return [
                'key' => $key,
                'label' => $label,
                'status' => self::STATUS_FAIL,
                'summary' => 'Automation status is unavailable.',
            ];
        }

        $status = match ($automationStatus['status']) {
            AutomationHealthService::STATUS_HEALTHY => self::STATUS_PASS,
            AutomationHealthService::STATUS_DEGRADED => self::STATUS_WARN,
            AutomationHealthService::STATUS_STALE,
            AutomationHealthService::STATUS_MISSING => self::STATUS_FAIL,
            default => self::STATUS_FAIL,
        };

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'summary' => $automationStatus['summary'],
        ];
    }

    private function automationStatusFor(array $automation, string $key): ?array
    {
        foreach ($automation['statuses'] ?? [] as $status) {
            if (($status['key'] ?? null) === $key) {
                return $status;
            }
        }

        return null;
    }

    private function verificationCacheTtlSeconds(): int
    {
        return max(300, (int) config('operations.verification_cache_ttl_seconds', 86400));
    }

    private function migrationPortabilityCheck(): array
    {
        $result = $this->migrationPortabilityService->verify();

        if (($result['status'] ?? 'fail') === 'pass') {
            return [
                'key' => 'migration_portability',
                'label' => 'Migration portability',
                'status' => self::STATUS_PASS,
                'summary' => 'Known fragile generated-column migration pattern was not detected.',
            ];
        }

        $firstIssue = $result['issues'][0]['summary'] ?? 'Migration portability verification failed.';

        return [
            'key' => 'migration_portability',
            'label' => 'Migration portability',
            'status' => self::STATUS_FAIL,
            'summary' => $firstIssue,
        ];
    }
}
