<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\AccessPointClaim;
use App\Models\BillingLedgerEntry;
use App\Models\Operator;
use App\Models\Site;
use App\Models\User;
use App\Services\AccessPointBillingService;
use App\Services\AccessPointHealthService;
use App\Services\OperatorAccountingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperatorAccountingTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 1;

    public function test_billed_ap_fee_increases_operator_payable_exactly_once(): void
    {
        $this->noteFreshAutomation();
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createOwnedAccessPoint($operator, $site);

        $entry = BillingLedgerEntry::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
            'direction' => BillingLedgerEntry::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'PHP',
            'state' => BillingLedgerEntry::STATE_POSTED,
            'billable_key' => "ap-connection-fee:{$accessPoint->id}",
            'triggered_at' => now(),
            'posted_at' => now(),
            'source' => BillingLedgerEntry::SOURCE_ADMIN_RUN,
        ]);

        $summary = app(OperatorAccountingService::class)->summary($operator);
        $statement = app(OperatorAccountingService::class)->statement($operator, 10);

        $this->assertSame(500.0, $summary['gross_billed_fees']);
        $this->assertSame(0.0, $summary['reversed_fees']);
        $this->assertSame(0.0, $summary['blocked_fees']);
        $this->assertSame(500.0, $summary['net_payable_fees']);
        $this->assertSame('healthy', $summary['confidence_state']);
        $this->assertSame($entry->id, $statement->first()['source_billing_ledger_entry_id']);
        $this->assertSame('500.00', $statement->first()['payable_effect_amount']);
        $this->assertTrue($statement->first()['affects_payable']);
    }

    public function test_reversal_offsets_operator_payable_correctly(): void
    {
        $this->noteFreshAutomation();
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createOwnedAccessPoint($operator, $site);

        $debit = BillingLedgerEntry::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
            'direction' => BillingLedgerEntry::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'PHP',
            'state' => BillingLedgerEntry::STATE_REVERSED,
            'billable_key' => "ap-connection-fee:{$accessPoint->id}",
            'triggered_at' => now()->subMinute(),
            'posted_at' => now()->subMinute(),
            'voided_at' => now(),
            'source' => BillingLedgerEntry::SOURCE_ADMIN_RUN,
        ]);

        BillingLedgerEntry::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
            'direction' => BillingLedgerEntry::DIRECTION_CREDIT,
            'amount' => 500,
            'currency' => 'PHP',
            'state' => BillingLedgerEntry::STATE_POSTED,
            'billable_key' => "ap-connection-fee-reversal:{$debit->id}",
            'triggered_at' => now(),
            'posted_at' => now(),
            'reversal_of_id' => $debit->id,
            'source' => BillingLedgerEntry::SOURCE_ADMIN_REVERSAL,
        ]);

        $summary = app(OperatorAccountingService::class)->summary($operator);

        $this->assertSame(500.0, $summary['gross_billed_fees']);
        $this->assertSame(500.0, $summary['reversed_fees']);
        $this->assertSame(0.0, $summary['net_payable_fees']);
    }

    public function test_blocked_unresolved_fee_does_not_count_as_payable(): void
    {
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createOwnedAccessPoint($operator, $site, [
            'billing_state' => AccessPoint::BILLING_STATE_BLOCKED,
            'billing_incident_state' => AccessPoint::BILLING_INCIDENT_CORRECTED_AFTER_BILLING,
        ]);

        BillingLedgerEntry::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
            'direction' => BillingLedgerEntry::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'PHP',
            'state' => BillingLedgerEntry::STATE_POSTED,
            'billable_key' => "ap-connection-fee:{$accessPoint->id}",
            'triggered_at' => now(),
            'posted_at' => now(),
            'source' => BillingLedgerEntry::SOURCE_ADMIN_RUN,
        ]);

        $summary = app(OperatorAccountingService::class)->summary($operator);
        $statement = app(OperatorAccountingService::class)->statement($operator, 10)->first();

        $this->assertSame(500.0, $summary['gross_billed_fees']);
        $this->assertSame(500.0, $summary['blocked_fees']);
        $this->assertSame(0.0, $summary['net_payable_fees']);
        $this->assertSame(1, $summary['unresolved_blocked_count']);
        $this->assertSame('degraded', $summary['confidence_state']);
        $this->assertFalse($statement['affects_payable']);
        $this->assertSame(AccessPoint::BILLING_INCIDENT_CORRECTED_AFTER_BILLING, $statement['blocked_reason']);
    }

    public function test_ownership_correction_reversal_and_rebill_produce_correct_net_balances(): void
    {
        $this->noteFreshAutomation();
        [, $oldOperator, $oldSite] = $this->createApprovedOperator('old@example.com', 'Old Operator');
        [, $newOperator, $newSite] = $this->createApprovedOperator('new@example.com', 'New Operator');
        $accessPoint = $this->createOwnedAccessPoint($oldOperator, $oldSite);

        $oldDebit = BillingLedgerEntry::query()->create([
            'operator_id' => $oldOperator->id,
            'site_id' => $oldSite->id,
            'access_point_id' => $accessPoint->id,
            'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
            'direction' => BillingLedgerEntry::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'PHP',
            'state' => BillingLedgerEntry::STATE_REVERSED,
            'billable_key' => "ap-connection-fee:{$accessPoint->id}",
            'triggered_at' => now()->subMinutes(2),
            'posted_at' => now()->subMinutes(2),
            'voided_at' => now()->subMinute(),
            'source' => BillingLedgerEntry::SOURCE_ADMIN_RUN,
        ]);

        BillingLedgerEntry::query()->create([
            'operator_id' => $oldOperator->id,
            'site_id' => $oldSite->id,
            'access_point_id' => $accessPoint->id,
            'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
            'direction' => BillingLedgerEntry::DIRECTION_CREDIT,
            'amount' => 500,
            'currency' => 'PHP',
            'state' => BillingLedgerEntry::STATE_POSTED,
            'billable_key' => "ap-connection-fee-reversal:{$oldDebit->id}",
            'triggered_at' => now()->subMinute(),
            'posted_at' => now()->subMinute(),
            'reversal_of_id' => $oldDebit->id,
            'source' => BillingLedgerEntry::SOURCE_ADMIN_REVERSAL,
        ]);

        $accessPoint->forceFill([
            'site_id' => $newSite->id,
            'claimed_by_operator_id' => $newOperator->id,
            'billing_state' => AccessPoint::BILLING_STATE_BILLED,
            'billing_incident_state' => null,
        ])->save();

        BillingLedgerEntry::query()->create([
            'operator_id' => $newOperator->id,
            'site_id' => $newSite->id,
            'access_point_id' => $accessPoint->id,
            'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
            'direction' => BillingLedgerEntry::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'PHP',
            'state' => BillingLedgerEntry::STATE_POSTED,
            'billable_key' => "ap-connection-fee:{$accessPoint->id}:rebill:1",
            'triggered_at' => now(),
            'posted_at' => now(),
            'source' => BillingLedgerEntry::SOURCE_ADMIN_RUN,
        ]);

        $oldSummary = app(OperatorAccountingService::class)->summary($oldOperator);
        $newSummary = app(OperatorAccountingService::class)->summary($newOperator);

        $this->assertSame(0.0, $oldSummary['net_payable_fees']);
        $this->assertSame(500.0, $newSummary['net_payable_fees']);
    }

    public function test_admin_and_operator_views_expose_accounting_totals_and_statement_traceability(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();

        $admin = User::factory()->create(['is_admin' => true]);
        [$operatorUser, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createOwnedAccessPoint($operator, $site);

        $entry = BillingLedgerEntry::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
            'direction' => BillingLedgerEntry::DIRECTION_DEBIT,
            'amount' => 500,
            'currency' => 'PHP',
            'state' => BillingLedgerEntry::STATE_POSTED,
            'billable_key' => "ap-connection-fee:{$accessPoint->id}",
            'triggered_at' => now(),
            'posted_at' => now(),
            'source' => BillingLedgerEntry::SOURCE_ADMIN_RUN,
        ]);

        $this->actingAs($admin)
            ->get("/admin/operators/{$operator->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Operators/Show')
                ->where('operator.summary.gross_billed_fees', '500.00')
                ->where('operator.summary.net_payable_fees', '500.00')
                ->where('recentAccountEntries.0.source_billing_ledger_entry_id', $entry->id)
                ->where('recentAccountEntries.0.affects_payable', true));

        $this->actingAs($operatorUser)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Payouts')
                ->where('summary.gross_billed_fees', '500.00')
                ->where('summary.net_payable_fees', '500.00')
                ->where('statementLines.0.source_billing_ledger_entry_id', $entry->id)
                ->where('statementLines.0.site.name', $site->name));
    }

    public function test_accounting_routes_remain_protected_correctly(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();

        [$operatorUser, $operator] = $this->createApprovedOperator();
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin-accounting@example.com']);

        $this->get('/operator/payouts')
            ->assertRedirect('/login');

        $this->actingAs($operatorUser)
            ->get("/admin/operators/{$operator->id}")
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/operator/payouts')
            ->assertForbidden();
    }

    private function createApprovedOperator(
        string $email = 'operator@example.com',
        string $businessName = 'North WiFi',
    ): array {
        $user = User::factory()->create([
            'is_admin' => false,
            'email' => $email,
        ]);

        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => $businessName,
            'contact_name' => $businessName.' Contact',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_APPROVED,
        ]);

        $site = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => $businessName.' Site',
            'slug' => str($businessName)->slug(),
            'omada_site_id' => 'site-'.self::$sequence,
        ]);

        return [$user, $operator, $site];
    }

    private function createOwnedAccessPoint(Operator $operator, Site $site, array $overrides = []): AccessPoint
    {
        $sequence = self::$sequence++;
        $serial = sprintf('SN-%06d', $sequence);
        $mac = sprintf('AA:BB:CC:%02X:%02X:%02X', ($sequence >> 16) & 0xFF, ($sequence >> 8) & 0xFF, $sequence & 0xFF);

        $admin = User::factory()->create(['is_admin' => true, 'email' => "admin-{$sequence}@example.com"]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => $serial,
            'requested_serial_number_normalized' => $serial,
            'requested_mac_address' => $mac,
            'requested_mac_address_normalized' => $mac,
            'claim_status' => AccessPointClaim::STATUS_ADOPTED,
            'claim_match_status' => AccessPointClaim::MATCH_STATUS_RESERVED,
            'claimed_at' => now()->subMinutes(20),
            'reviewed_at' => now()->subMinutes(18),
            'reviewed_by_user_id' => $admin->id,
        ]);

        return AccessPoint::query()->create(array_merge([
            'site_id' => $site->id,
            'claimed_by_operator_id' => $operator->id,
            'approved_claim_id' => $claim->id,
            'serial_number' => $serial,
            'omada_device_id' => 'device-'.$sequence,
            'name' => 'AP '.$sequence,
            'mac_address' => $mac,
            'vendor' => 'TP-Link',
            'model' => 'EAP650',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'adoption_state' => AccessPoint::ADOPTION_STATE_ADOPTED,
            'claimed_at' => now()->subMinutes(20),
            'ownership_verified_at' => now()->subMinutes(10),
            'ownership_verified_by_user_id' => $admin->id,
            'billing_state' => AccessPoint::BILLING_STATE_BILLED,
            'last_synced_at' => now()->subMinute(),
            'is_online' => true,
            'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
            'health_checked_at' => now()->subMinute(),
            'status_source' => AccessPoint::STATUS_SOURCE_RECONCILE,
            'status_source_event_at' => now()->subMinute(),
            'last_seen_at' => now()->subMinute(),
            'first_confirmed_connected_at' => now()->subMinutes(5),
            'health_metadata' => [
                'confidence' => 'confirmed',
            ],
        ], $overrides));
    }

    private function noteFreshAutomation(): void
    {
        $now = now()->toIso8601String();

        Cache::put(AccessPointHealthService::SYNC_HEARTBEAT_CACHE_KEY, $now, now()->addDay());
        Cache::put(AccessPointHealthService::RECONCILE_HEARTBEAT_CACHE_KEY, $now, now()->addDay());
        Cache::put(AccessPointBillingService::POST_HEARTBEAT_CACHE_KEY, $now, now()->addDay());
    }
}
