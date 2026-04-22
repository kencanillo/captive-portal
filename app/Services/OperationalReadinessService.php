<?php

namespace App\Services;

use App\Models\ControllerSetting;
use RuntimeException;

class OperationalReadinessService
{
    public const STATE_HEALTHY = 'healthy';
    public const STATE_WARNING = 'warning';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_BLOCKED = 'blocked';

    public const ACTION_ADMIN_RETRY_RELEASE = 'admin_retry_release';
    public const ACTION_BILLING_POST = 'billing_post';
    public const ACTION_BILLING_RESOLUTION = 'billing_resolution';
    public const ACTION_PAYOUT_REQUEST_CREATE = 'payout_request_create';
    public const ACTION_PAYOUT_REVIEW = 'payout_review';
    public const ACTION_PAYOUT_SETTLEMENT = 'payout_settlement';
    public const ACTION_PAYOUT_EXECUTION = 'payout_execution';

    public function __construct(
        private readonly AutomationHealthService $automationHealthService,
        private readonly AccessPointBillingService $accessPointBillingService,
    ) {
    }

    public function summary(): array
    {
        $automation = $this->automationHealthService->statusSummary();
        $billingRuntime = $this->accessPointBillingService->runtimeHealth();

        $actions = [
            $this->actionForReleaseRetry($automation),
            $this->actionForBillingPost($automation, $billingRuntime),
            $this->actionForBillingResolution($automation),
            $this->actionForPayoutRequestCreate($automation),
            $this->actionForPayoutReview($automation),
            $this->actionForPayoutSettlement($automation),
            $this->actionForPayoutExecution($automation),
        ];

        $incidents = array_values(array_filter([
            ...($automation['incidents'] ?? []),
            ...array_map(function (array $action): ?array {
                if (! in_array($action['state'], [self::STATE_DEGRADED, self::STATE_BLOCKED], true)) {
                    return null;
                }

                return [
                    'key' => $action['key'],
                    'label' => $action['label'],
                    'severity' => $action['state'],
                    'summary' => $action['summary'],
                    'incident_open' => true,
                    'blocking' => $action['blocking'],
                ];
            }, $actions),
        ]));

        return [
            'overall_state' => $this->overallState($incidents),
            'incidents' => $incidents,
            'actions' => $actions,
        ];
    }

    public function action(string $action): array
    {
        $summary = $this->summary();

        foreach ($summary['actions'] as $candidate) {
            if ($candidate['key'] === $action) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unknown operational readiness action.');
    }

    public function assertActionReady(string $action): void
    {
        $candidate = $this->action($action);

        if ($candidate['blocking']) {
            throw new RuntimeException($candidate['summary']);
        }
    }

    private function actionForReleaseRetry(array $automation): array
    {
        $queueWorker = $this->automationStatusFor($automation, 'queue_worker');
        $settings = ControllerSetting::query()->first();

        if (! $settings || blank($settings->base_url) || ! $settings->hasHotspotOperatorCredentials()) {
            return $this->blockedAction(
                self::ACTION_ADMIN_RETRY_RELEASE,
                'Admin retry release',
                'Release retry is blocked because the controller base URL or hotspot operator credentials are missing.'
            );
        }

        if (! $queueWorker || $queueWorker['status'] !== AutomationHealthService::STATUS_HEALTHY) {
            return $this->blockedAction(
                self::ACTION_ADMIN_RETRY_RELEASE,
                'Admin retry release',
                'Release retry is blocked because the queue worker heartbeat is not healthy.'
            );
        }

        return $this->healthyAction(self::ACTION_ADMIN_RETRY_RELEASE, 'Admin retry release', 'Queue worker and controller release prerequisites are healthy.');
    }

    private function actionForBillingPost(array $automation, array $billingRuntime): array
    {
        if (($billingRuntime['candidate_count'] ?? 0) === 0) {
            return $this->warningAction(
                self::ACTION_BILLING_POST,
                'Billing post',
                'No AP billing candidates currently need posting.'
            );
        }

        if ($this->billingAutomationBlocked($automation)) {
            return $this->blockedAction(
                self::ACTION_BILLING_POST,
                'Billing post',
                'Billing post is blocked because AP sync or AP health reconcile runtime is unhealthy.'
            );
        }

        return $this->healthyAction(self::ACTION_BILLING_POST, 'Billing post', 'Billing prerequisites are healthy.');
    }

    private function actionForBillingResolution(array $automation): array
    {
        if ($this->billingAutomationBlocked($automation)) {
            return $this->blockedAction(
                self::ACTION_BILLING_RESOLUTION,
                'Billing incident resolution',
                'Billing incident resolution is blocked because AP sync or AP health reconcile runtime is unhealthy.'
            );
        }

        return $this->healthyAction(
            self::ACTION_BILLING_RESOLUTION,
            'Billing incident resolution',
            'Billing resolution prerequisites are healthy.'
        );
    }

    private function actionForPayoutRequestCreate(array $automation): array
    {
        if ($this->billingAutomationBlocked($automation) || $this->billingPostUnhealthy($automation)) {
            return $this->blockedAction(
                self::ACTION_PAYOUT_REQUEST_CREATE,
                'Payout request creation',
                'Payout request creation is blocked because AP sync, AP health reconcile, or billing-post automation is unhealthy.'
            );
        }

        return $this->healthyAction(
            self::ACTION_PAYOUT_REQUEST_CREATE,
            'Payout request creation',
            'Accounting automation prerequisites are healthy for payout requests.'
        );
    }

    private function actionForPayoutReview(array $automation): array
    {
        if ($this->billingAutomationBlocked($automation) || $this->billingPostUnhealthy($automation)) {
            return $this->blockedAction(
                self::ACTION_PAYOUT_REVIEW,
                'Payout review',
                'Payout review is blocked because AP sync, AP health reconcile, or billing-post automation is unhealthy.'
            );
        }

        return $this->healthyAction(
            self::ACTION_PAYOUT_REVIEW,
            'Payout review',
            'Accounting automation prerequisites are healthy for payout review.'
        );
    }

    private function actionForPayoutSettlement(array $automation): array
    {
        if ($this->billingAutomationBlocked($automation) || $this->billingPostUnhealthy($automation)) {
            return $this->blockedAction(
                self::ACTION_PAYOUT_SETTLEMENT,
                'Payout settlement',
                'Payout settlement is blocked because AP sync, AP health reconcile, or billing-post automation is unhealthy.'
            );
        }

        return $this->healthyAction(
            self::ACTION_PAYOUT_SETTLEMENT,
            'Payout settlement',
            'Accounting automation prerequisites are healthy for manual payout settlement.'
        );
    }

    private function actionForPayoutExecution(array $automation): array
    {
        if ($this->billingAutomationBlocked($automation) || $this->billingPostUnhealthy($automation)) {
            return $this->blockedAction(
                self::ACTION_PAYOUT_EXECUTION,
                'Payout execution',
                'Payout execution is blocked because AP sync, AP health reconcile, or billing-post automation is unhealthy.'
            );
        }

        return $this->healthyAction(
            self::ACTION_PAYOUT_EXECUTION,
            'Payout execution',
            'Accounting automation prerequisites are healthy for payout execution.'
        );
    }

    private function billingAutomationBlocked(array $automation): bool
    {
        foreach (['ap_sync', 'ap_health_reconcile'] as $key) {
            $status = $this->automationStatusFor($automation, $key);

            if (! $status || $status['status'] !== AutomationHealthService::STATUS_HEALTHY) {
                return true;
            }
        }

        return false;
    }

    private function billingPostUnhealthy(array $automation): bool
    {
        $status = $this->automationStatusFor($automation, 'billing_post');

        return ! $status || $status['status'] !== AutomationHealthService::STATUS_HEALTHY;
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

    private function overallState(array $incidents): string
    {
        if (collect($incidents)->contains(fn (array $incident) => ($incident['severity'] ?? null) === self::STATE_BLOCKED)) {
            return self::STATE_BLOCKED;
        }

        if (collect($incidents)->contains(fn (array $incident) => ($incident['severity'] ?? null) === self::STATE_DEGRADED)) {
            return self::STATE_DEGRADED;
        }

        if (collect($incidents)->contains(fn (array $incident) => ($incident['severity'] ?? null) === self::STATE_WARNING)) {
            return self::STATE_WARNING;
        }

        return self::STATE_HEALTHY;
    }

    private function healthyAction(string $key, string $label, string $summary): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'state' => self::STATE_HEALTHY,
            'summary' => $summary,
            'blocking' => false,
        ];
    }

    private function warningAction(string $key, string $label, string $summary): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'state' => self::STATE_WARNING,
            'summary' => $summary,
            'blocking' => false,
        ];
    }

    private function blockedAction(string $key, string $label, string $summary): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'state' => self::STATE_BLOCKED,
            'summary' => $summary,
            'blocking' => true,
        ];
    }
}
