<?php

namespace App\Services;

use App\Models\AccessPoint;
use App\Models\BillingLedgerEntry;
use App\Models\Operator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OperatorAccountingService
{
    public function __construct(
        private readonly AutomationHealthService $automationHealthService,
    ) {
    }

    public function summary(Operator $operator): array
    {
        $grossBilledFees = (float) BillingLedgerEntry::query()
            ->where('operator_id', $operator->id)
            ->where('entry_type', BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE)
            ->where('direction', BillingLedgerEntry::DIRECTION_DEBIT)
            ->sum('amount');

        $reversedFees = (float) BillingLedgerEntry::query()
            ->where('operator_id', $operator->id)
            ->where('entry_type', BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE)
            ->where('direction', BillingLedgerEntry::DIRECTION_CREDIT)
            ->sum('amount');

        $blockedActiveDebitRows = $this->blockedActiveDebitQuery($operator)->get();
        $blockedFees = (float) $blockedActiveDebitRows->sum(fn ($row) => (float) $row->amount);
        $unresolvedBlockedCount = $blockedActiveDebitRows->count();
        $netPayableFees = (float) round(max(0.0, $grossBilledFees - $reversedFees - $blockedFees), 2);

        $automation = $this->automationHealthService->statusSummary();
        $confidenceReasons = [];

        foreach (['ap_sync', 'ap_health_reconcile', 'billing_post'] as $key) {
            $status = $this->automationStatusFor($automation, $key);

            if (! $status || ($status['status'] ?? null) !== AutomationHealthService::STATUS_HEALTHY) {
                $confidenceReasons[] = $status['summary']
                    ?? 'Critical automation required for AP-fee recognition is unhealthy.';
            }
        }

        if ($unresolvedBlockedCount > 0) {
            $confidenceReasons[] = sprintf(
                '%d AP fee entries are blocked by unresolved billing incidents and excluded from payable balance.',
                $unresolvedBlockedCount
            );
        }

        return [
            'currency' => $this->statementCurrency(),
            'gross_billed_fees' => round($grossBilledFees, 2),
            'reversed_fees' => round($reversedFees, 2),
            'blocked_fees' => round($blockedFees, 2),
            'net_payable_fees' => $netPayableFees,
            'unresolved_blocked_count' => $unresolvedBlockedCount,
            'confidence_state' => empty($confidenceReasons) ? 'healthy' : 'degraded',
            'confidence_reasons' => $confidenceReasons,
        ];
    }

    public function statement(Operator $operator, int $limit = 10): Collection
    {
        $blockedEntryIds = $this->blockedActiveDebitQuery($operator)
            ->pluck('billing_ledger_entries.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return BillingLedgerEntry::query()
            ->with(['accessPoint:id,name,mac_address,billing_incident_state', 'site:id,name'])
            ->where('operator_id', $operator->id)
            ->where('entry_type', BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE)
            ->latest('posted_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (BillingLedgerEntry $entry) use ($blockedEntryIds): array {
                $blocked = $entry->direction === BillingLedgerEntry::DIRECTION_DEBIT
                    && in_array($entry->id, $blockedEntryIds, true);

                $payableEffect = match (true) {
                    $entry->direction === BillingLedgerEntry::DIRECTION_CREDIT => round((float) $entry->amount * -1, 2),
                    $blocked => 0.0,
                    default => round((float) $entry->amount, 2),
                };

                return [
                    'id' => $entry->id,
                    'source_billing_ledger_entry_id' => $entry->id,
                    'entry_type' => $entry->entry_type,
                    'direction' => $entry->direction,
                    'state' => $entry->state,
                    'amount' => number_format((float) $entry->amount, 2, '.', ''),
                    'currency' => $entry->currency,
                    'payable_effect_amount' => number_format($payableEffect, 2, '.', ''),
                    'affects_payable' => ! $blocked,
                    'blocked_reason' => $blocked ? $entry->accessPoint?->billing_incident_state : null,
                    'recognized_at' => optional($entry->posted_at)?->toDateTimeString(),
                    'access_point' => $entry->accessPoint ? [
                        'id' => $entry->accessPoint->id,
                        'name' => $entry->accessPoint->name,
                        'mac_address' => $entry->accessPoint->mac_address,
                    ] : null,
                    'site' => $entry->site ? [
                        'id' => $entry->site->id,
                        'name' => $entry->site->name,
                    ] : null,
                    'reversal_of_id' => $entry->reversal_of_id,
                    'source' => $entry->source,
                    'metadata' => $entry->metadata,
                ];
            });
    }

    private function blockedActiveDebitQuery(Operator $operator)
    {
        return BillingLedgerEntry::query()
            ->select('billing_ledger_entries.*')
            ->leftJoin('billing_ledger_entries as reversals', 'reversals.reversal_of_id', '=', 'billing_ledger_entries.id')
            ->join('access_points', 'access_points.id', '=', 'billing_ledger_entries.access_point_id')
            ->where('billing_ledger_entries.operator_id', $operator->id)
            ->where('billing_ledger_entries.entry_type', BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE)
            ->where('billing_ledger_entries.direction', BillingLedgerEntry::DIRECTION_DEBIT)
            ->whereNull('billing_ledger_entries.reversal_of_id')
            ->whereNull('reversals.id')
            ->where('access_points.billing_state', AccessPoint::BILLING_STATE_BLOCKED)
            ->whereNotNull('access_points.billing_incident_state');
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

    private function statementCurrency(): string
    {
        return (string) config('omada.billing_connection_fee_currency', 'PHP');
    }
}
