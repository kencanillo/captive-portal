<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\AccessPointClaim;
use App\Models\BillingLedgerEntry;
use App\Models\Operator;
use App\Models\PayoutExecutionAttempt;
use App\Models\PayoutExecutionAttemptResolution;
use App\Models\PayoutRequest;
use App\Models\PayoutRequestResolution;
use App\Models\PayoutSettlement;
use App\Models\PayoutSettlementCorrection;
use App\Models\Site;
use App\Models\User;
use App\Services\AccessPointBillingService;
use App\Services\AccessPointHealthService;
use App\Services\OperatorPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperatorPayoutRequestTest extends TestCase
{
    use RefreshDatabase;

    private static int $sequence = 1;

    public function test_operator_can_request_payout_only_up_to_requestable_balance(): void
    {
        $this->noteFreshAutomation();
        [$user, $operator] = $this->createApprovedOperator();
        $this->createLedgerBackedAccessPoint($operator);

        $this->actingAs($user)
            ->post('/operator/payouts', [
                'amount' => 500,
                'destination_type' => 'bank',
                'destination_account_name' => 'North WiFi',
                'destination_account_reference' => '1234567890',
                'destination_provider' => 'instapay',
            ])
            ->assertRedirect('/operator/payouts');

        $this->assertDatabaseHas('payout_requests', [
            'operator_id' => $operator->id,
            'amount' => 500,
            'status' => PayoutRequest::STATUS_PENDING_REVIEW,
            'destination_type' => 'bank',
        ]);
        $this->assertDatabaseCount('billing_ledger_entries', 1);
    }

    public function test_duplicate_request_attempts_cannot_over_reserve_same_balance(): void
    {
        $this->noteFreshAutomation();
        [$user, $operator] = $this->createApprovedOperator('operator-duplicate@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $this->actingAs($user)
            ->post('/operator/payouts', [
                'amount' => 300,
                'destination_type' => 'bank',
                'destination_account_name' => 'North WiFi',
                'destination_account_reference' => '1234567890',
                'destination_provider' => 'instapay',
            ])
            ->assertRedirect('/operator/payouts');

        $this->actingAs($user)
            ->post('/operator/payouts', [
                'amount' => 250,
                'destination_type' => 'bank',
                'destination_account_name' => 'North WiFi',
                'destination_account_reference' => '1234567890',
                'destination_provider' => 'instapay',
            ])
            ->assertSessionHasErrors([
                'amount' => 'Payout amount exceeds the requestable balance.',
            ]);

        $this->assertDatabaseCount('payout_requests', 1);
    }

    public function test_pending_and_approved_requests_reduce_requestable_balance_correctly(): void
    {
        $this->noteFreshAutomation();
        [, $operator] = $this->createApprovedOperator('operator-summary@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 150,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_PENDING_REVIEW,
            'requested_at' => now()->subMinutes(2),
        ]);
        PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 100,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
        ]);

        $summary = app(OperatorPayoutService::class)->summary($operator);

        $this->assertSame(500.0, $summary['net_payable_fees']);
        $this->assertSame(150.0, $summary['pending_review_reserved']);
        $this->assertSame(100.0, $summary['approved_unpaid_reserved']);
        $this->assertSame(250.0, $summary['reserved_for_payout']);
        $this->assertSame(250.0, $summary['requestable_balance']);
    }

    public function test_rejected_and_cancelled_requests_release_reserved_balance_safely(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-release@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $pendingRequest = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 200,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_PENDING_REVIEW,
            'requested_at' => now()->subMinutes(2),
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay'],
        ]);

        $approvedRequest = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 100,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$pendingRequest->id}/reject", [
                'review_notes' => 'Insufficient paperwork',
            ])
            ->assertRedirect('/admin/payout-requests');

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$approvedRequest->id}/cancel", [
                'review_notes' => 'Operator requested cancellation',
            ])
            ->assertRedirect('/admin/payout-requests');

        $summary = app(OperatorPayoutService::class)->summary($operator);

        $this->assertSame(0.0, $summary['reserved_for_payout']);
        $this->assertSame(500.0, $summary['requestable_balance']);
        $this->assertDatabaseHas('payout_requests', [
            'id' => $pendingRequest->id,
            'status' => PayoutRequest::STATUS_REJECTED,
        ]);
        $this->assertDatabaseHas('payout_requests', [
            'id' => $approvedRequest->id,
            'status' => PayoutRequest::STATUS_CANCELLED,
            'cancellation_reason' => 'Operator requested cancellation',
        ]);
    }

    public function test_admin_approval_does_not_mark_request_as_settled(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-approve@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $payoutRequest = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 30,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_PENDING_REVIEW,
            'requested_at' => now(),
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$payoutRequest->id}/approve")
            ->assertRedirect('/admin/payout-requests');

        $payoutRequest->refresh();
        $summary = app(OperatorPayoutService::class)->summary($operator);

        $this->assertSame(PayoutRequest::STATUS_APPROVED, $payoutRequest->status);
        $this->assertSame(PayoutRequest::MODE_MANUAL, $payoutRequest->processing_mode);
        $this->assertSame('approved_unpaid', $payoutRequest->provider_status);
        $this->assertNull($payoutRequest->paid_at);
        $this->assertDatabaseCount('payout_settlements', 0);
        $this->assertSame(30.0, $summary['approved_unpaid_reserved']);
        $this->assertSame(470.0, $summary['requestable_balance']);
        $this->assertSame(PayoutRequest::SETTLEMENT_STATE_READY, $payoutRequest->settlement_state);
    }

    public function test_ready_approved_request_can_be_manually_settled_exactly_once(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [$user, $operator] = $this->createApprovedOperator('operator-settle@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/settle", [
                'amount' => 300,
                'settlement_reference' => 'MANUAL-REF-001',
                'notes' => 'Settled in bank portal.',
            ])
            ->assertRedirect('/admin/payout-requests');

        $request->refresh();
        $summary = app(OperatorPayoutService::class)->summary($operator);

        $this->assertSame(PayoutRequest::STATUS_SETTLED, $request->status);
        $this->assertSame(PayoutRequest::SETTLEMENT_STATE_SETTLED, $request->settlement_state);
        $this->assertNotNull($request->paid_at);
        $this->assertDatabaseHas('payout_settlements', [
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'settlement_reference' => 'MANUAL-REF-001',
        ]);
        $this->assertSame(300.0, $summary['paid_out']);
        $this->assertSame(0.0, $summary['approved_unpaid_reserved']);
        $this->assertSame(200.0, $summary['requestable_balance']);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/settle", [
                'amount' => 300,
                'settlement_reference' => 'MANUAL-REF-002',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Only approved payout requests can be settled.');

        $this->assertDatabaseCount('payout_settlements', 1);

        $this->actingAs($user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.settled_total', '300.00')
                ->where('completedRequests.0.status', PayoutRequest::STATUS_SETTLED)
                ->where('completedRequests.0.settlement.settlement_reference', 'MANUAL-REF-001'));
    }

    public function test_eligible_payout_request_can_create_exactly_one_execution_attempt(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [$user, $operator] = $this->createApprovedOperator('operator-exec@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'manual',
            ])
            ->assertRedirect('/admin/payout-requests');

        $request->refresh();
        $request->load('latestExecutionAttempt');
        $summary = app(OperatorPayoutService::class)->summary($operator);

        $this->assertNotNull($request->latestExecutionAttempt);
        $this->assertSame(PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED, $request->latestExecutionAttempt->execution_state);
        $this->assertSame('manual', $request->latestExecutionAttempt->provider_name);
        $this->assertSame('PXR-'.$request->id.'-01', $request->latestExecutionAttempt->execution_reference);
        $this->assertSame('payout-execution:'.$request->id.':01', $request->latestExecutionAttempt->idempotency_key);
        $this->assertSame('bank', $request->latestExecutionAttempt->provider_request_metadata['destination_type']);
        $this->assertSame('Manual payout execution stub recorded. No external transfer was sent.', $request->latestExecutionAttempt->provider_response_metadata['message']);
        $this->assertDatabaseHas('payout_execution_attempts', [
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'execution_state' => PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
            'provider_name' => 'manual',
        ]);
        $this->assertSame(1, $summary['execution_in_flight_count']);
        $this->assertDatabaseCount('payout_settlements', 0);

        $this->actingAs($user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingRequests.0.latest_execution_attempt.execution_state', PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED)
                ->where('pendingRequests.0.latest_execution_attempt.execution_reference', 'PXR-'.$request->id.'-01'));

        $this->actingAs($admin)
            ->get('/admin/payout-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payoutRequests.0.latest_execution_attempt.execution_state', PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED)
                ->where('payoutRequests.0.latest_execution_attempt.execution_reference', 'PXR-'.$request->id.'-01'));
    }

    public function test_duplicate_execution_trigger_does_not_create_duplicate_attempts(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-exec-duplicate@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $admin->id,
        ]);

        PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'triggered_at' => now(),
            'triggered_by_user_id' => $admin->id,
            'provider_name' => 'manual',
            'provider_request_metadata' => ['stub' => true],
            'provider_response_metadata' => ['message' => 'existing'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'manual',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'This payout request already has an active execution attempt.');

        $this->assertDatabaseCount('payout_execution_attempts', 1);
    }

    public function test_non_ready_invalidated_and_cancelled_requests_cannot_enter_execution(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-exec-blocked@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $pendingReview = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_PENDING_REVIEW,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_NOT_READY,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$pendingReview->id}/trigger-execution", [
                'provider' => 'manual',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Only approved payout requests with settlement state ready can enter payout execution.');

        $cancelled = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_CANCELLED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_NOT_READY,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$cancelled->id}/trigger-execution", [
                'provider' => 'manual',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Only approved payout requests with settlement state ready can enter payout execution.');

        $reviewRequired = $this->createReviewRequiredRequest($operator, $admin, 200, 'NO-EXEC-REV');

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$reviewRequired->id}/trigger-execution", [
                'provider' => 'manual',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Only approved payout requests with settlement state ready can enter payout execution.');

        $this->assertDatabaseCount('payout_execution_attempts', 0);
    }

    public function test_execution_trigger_is_blocked_when_readiness_is_unhealthy(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-exec-readiness@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'manual',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Payout execution is blocked because AP sync, AP health reconcile, or billing-post automation is unhealthy.');

        $this->assertDatabaseCount('payout_execution_attempts', 0);
    }

    public function test_dispatch_through_paymongo_adapter_records_provider_metadata_safely(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        $admin = User::factory()->create(['is_admin' => true]);
        [$user, $operator] = $this->createApprovedOperator('operator-paymongo-dispatch@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay', 'bic' => 'TESTPHM2XXX'],
        ]);

        Http::fake([
            'https://api.paymongo.test/v1/wallets/wallet_test_123/transactions' => Http::response([
                'data' => [
                    'id' => 'wallet_tr_test_001',
                    'attributes' => [
                        'status' => 'pending',
                        'reference_number' => 'PM-REF-001',
                        'transfer_id' => 'tr_test_001',
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'paymongo',
            ])
            ->assertRedirect('/admin/payout-requests');

        Http::assertSent(function ($outbound) use ($request) {
            return $outbound->url() === 'https://api.paymongo.test/v1/wallets/wallet_test_123/transactions'
                && $outbound->hasHeader('Idempotency-Key')
                && $outbound->header('Idempotency-Key')[0] === 'payout-execution:'.$request->id.':01';
        });

        $request->refresh()->load('latestExecutionAttempt');
        $attempt = $request->latestExecutionAttempt;

        $this->assertNotNull($attempt);
        $this->assertSame('paymongo', $attempt->provider_name);
        $this->assertSame('pending', $attempt->provider_state);
        $this->assertSame('dispatch', $attempt->provider_state_source);
        $this->assertSame(PayoutExecutionAttempt::STATE_DISPATCHED, $attempt->execution_state);
        $this->assertSame('wallet_tr_test_001', $attempt->external_reference);
        $this->assertSame('tr_test_001', data_get($attempt->provider_response_metadata, 'transfer_id'));
        $this->assertStringContainsString("/api/paymongo/payout-executions/{$attempt->id}/callback", data_get($attempt->provider_request_metadata, 'data.attributes.callback_url'));
        $this->assertDatabaseCount('payout_settlements', 0);
        $this->assertSame(PayoutRequest::STATUS_APPROVED, $request->status);

        $this->actingAs($admin)
            ->get('/admin/payout-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payoutRequests.0.latest_execution_attempt.provider_name', 'paymongo')
                ->where('payoutRequests.0.latest_execution_attempt.provider_state', 'pending'));

        $this->actingAs($user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingRequests.0.latest_execution_attempt.provider_name', 'paymongo')
                ->where('pendingRequests.0.latest_execution_attempt.provider_state', 'pending'));
    }

    public function test_duplicate_paymongo_dispatch_does_not_create_duplicate_provider_attempts(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-paymongo-duplicate@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay', 'bic' => 'TESTPHM2XXX'],
        ]);

        Http::fake([
            'https://api.paymongo.test/v1/wallets/wallet_test_123/transactions' => Http::response([
                'data' => [
                    'id' => 'wallet_tr_test_dup_001',
                    'attributes' => [
                        'status' => 'pending',
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'paymongo',
            ])
            ->assertRedirect('/admin/payout-requests');

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'paymongo',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'This payout request already has an active execution attempt.');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('payout_execution_attempts', 1);
    }

    public function test_background_reconcile_command_recovers_missing_callback_by_poll_safely(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-paymongo-background-reconcile@example.com');
        $this->createLedgerBackedAccessPoint($operator);
        config()->set('payouts.execution.dispatched_stale_minutes', 1);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinutes(5),
            'reviewed_at' => now()->subMinutes(4),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay', 'bic' => 'TESTPHM2XXX'],
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_DISPATCHED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'external_reference' => 'wallet_tr_test_background_001',
            'provider_name' => 'paymongo',
            'provider_state' => 'pending',
            'provider_state_source' => 'dispatch',
            'provider_state_checked_at' => now()->subMinutes(4),
            'triggered_at' => now()->subMinutes(4),
            'triggered_by_user_id' => $admin->id,
        ]);

        Http::fake([
            'https://api.paymongo.test/v1/wallets/wallet_test_123/transactions/wallet_tr_test_background_001' => Http::response([
                'data' => [
                    'id' => 'wallet_tr_test_background_001',
                    'attributes' => [
                        'status' => 'succeeded',
                        'reference_number' => 'PM-BG-001',
                        'transfer_id' => 'tr_bg_001',
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('payouts:reconcile-execution-attempts', ['--limit' => 10])->assertExitCode(0);

        $attempt->refresh();
        $checkedAt = optional($attempt->provider_state_checked_at)?->toDateTimeString();

        $this->assertSame(PayoutExecutionAttempt::STATE_COMPLETED, $attempt->execution_state);
        $this->assertSame('succeeded', $attempt->provider_state);
        $this->assertSame('poll', $attempt->provider_state_source);
        $this->assertNotNull($attempt->last_reconciled_at);

        $this->artisan('payouts:reconcile-execution-attempts', ['--limit' => 10])->assertExitCode(0);

        $attempt->refresh();
        $this->assertSame($checkedAt, optional($attempt->provider_state_checked_at)?->toDateTimeString());
        $this->assertDatabaseCount('payout_execution_attempts', 1);
    }

    public function test_paymongo_dispatch_is_blocked_when_provider_configuration_is_incomplete(): void
    {
        $this->noteFreshAutomation();
        config()->set('payouts.execution_provider', 'paymongo');
        config()->set('payouts.providers.paymongo.enabled', true);
        config()->set('services.paymongo.secret_key', 'sk_test_123');
        config()->set('services.paymongo.payout_webhook_secret', '');
        config()->set('services.paymongo.payout_callback_url', 'https://portal.example.com');

        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-paymongo-config-blocked@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay', 'bic' => 'TESTPHM2XXX'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'paymongo',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'PayMongo payout execution is blocked because the payout wallet ID is missing.');

        $this->assertDatabaseCount('payout_execution_attempts', 0);
    }

    public function test_paymongo_dispatch_is_blocked_when_destination_preflight_fails(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-paymongo-destination-blocked@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'paymongo',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'PayMongo payout execution is blocked because the bank code is missing or malformed.');

        $this->assertDatabaseCount('payout_execution_attempts', 0);
    }

    public function test_retryable_paymongo_execution_is_bounded_by_retry_budget(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        config()->set('payouts.execution.max_retry_attempts', 2);
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-paymongo-retry-budget@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinutes(5),
            'reviewed_at' => now()->subMinutes(4),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay', 'bic' => 'TESTPHM2XXX'],
        ]);

        $firstAttempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_RETRYABLE_FAILED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'external_reference' => 'wallet_tr_retry_001',
            'provider_name' => 'paymongo',
            'provider_state' => 'failed',
            'provider_state_source' => 'poll',
            'provider_state_checked_at' => now()->subMinutes(3),
            'triggered_at' => now()->subMinutes(4),
            'completed_at' => now()->subMinutes(3),
            'triggered_by_user_id' => $admin->id,
        ]);

        $secondAttempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'parent_attempt_id' => $firstAttempt->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_RETRYABLE_FAILED,
            'execution_reference' => 'PXR-'.$request->id.'-02',
            'idempotency_key' => 'payout-execution:'.$request->id.':02',
            'external_reference' => 'wallet_tr_retry_002',
            'provider_name' => 'paymongo',
            'provider_state' => 'failed',
            'provider_state_source' => 'poll',
            'provider_state_checked_at' => now()->subMinutes(2),
            'triggered_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinutes(2),
            'triggered_by_user_id' => $admin->id,
        ]);

        $thirdAttempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'parent_attempt_id' => $secondAttempt->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_RETRYABLE_FAILED,
            'execution_reference' => 'PXR-'.$request->id.'-03',
            'idempotency_key' => 'payout-execution:'.$request->id.':03',
            'external_reference' => 'wallet_tr_retry_003',
            'provider_name' => 'paymongo',
            'provider_state' => 'failed',
            'provider_state_source' => 'poll',
            'provider_state_checked_at' => now()->subMinute(),
            'triggered_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'triggered_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$thirdAttempt->id}/retry", [
                'reason' => 'Retry again',
                'provider' => 'paymongo',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Payout execution retry is blocked because the retry budget is exhausted for this payout request.');

        $this->assertDatabaseCount('payout_execution_attempts', 3);
    }

    public function test_admin_and_operator_views_expose_provider_ops_health_and_execution_block_reason(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();
        config()->set('payouts.execution_provider', 'paymongo');
        config()->set('payouts.providers.paymongo.enabled', true);
        config()->set('services.paymongo.secret_key', 'sk_test_123');
        config()->set('services.paymongo.payout_wallet_id', 'wallet_test_123');
        config()->set('services.paymongo.payout_webhook_secret', '');
        config()->set('services.paymongo.payout_callback_url', 'https://portal.example.com');

        $admin = User::factory()->create(['is_admin' => true]);
        [$user, $operator] = $this->createApprovedOperator('operator-provider-ops-view@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay', 'bic' => 'TESTPHM2XXX'],
        ]);

        $this->actingAs($admin)
            ->get('/admin/payout-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('providerOps.provider', 'paymongo')
                ->where('providerOps.provider_readiness.ready', false)
                ->where('payoutRequests.0.execution_preflight.blocking_reason', 'PayMongo payout execution is blocked because the payout webhook secret is missing.'));

        $this->actingAs($user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('providerOps.provider', 'paymongo')
                ->where('providerOps.provider_readiness.ready', false)
                ->where('pendingRequests.0.execution_preflight.blocking_reason', 'PayMongo payout execution is blocked because the payout webhook secret is missing.'));
    }

    public function test_verified_paymongo_callback_updates_execution_state_correctly_and_duplicate_callback_no_ops_safely(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        [, $operator] = $this->createApprovedOperator('operator-paymongo-callback@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_DISPATCHED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'external_reference' => 'wallet_tr_test_cb_001',
            'provider_name' => 'paymongo',
            'provider_state' => 'pending',
            'provider_state_source' => 'dispatch',
            'provider_state_checked_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => User::factory()->create(['is_admin' => true])->id,
        ]);

        $payload = [
            'data' => [
                'id' => 'wallet_tr_test_cb_001',
                'type' => 'wallet_transaction',
                'attributes' => [
                    'status' => 'succeeded',
                    'reference_number' => 'PM-CB-001',
                    'transfer_id' => 'tr_cb_001',
                ],
            ],
        ];

        $signature = $this->signPayMongoPayload(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $this->postJson("/api/paymongo/payout-executions/{$attempt->id}/callback", $payload, [
            'Paymongo-Signature' => $signature,
        ])->assertOk();

        $attempt->refresh();
        $request->refresh();

        $this->assertSame(PayoutExecutionAttempt::STATE_COMPLETED, $attempt->execution_state);
        $this->assertSame('succeeded', $attempt->provider_state);
        $this->assertSame('callback', $attempt->provider_state_source);
        $this->assertNull($attempt->last_error);
        $this->assertDatabaseCount('payout_settlements', 0);
        $this->assertSame(PayoutRequest::STATUS_APPROVED, $request->status);
        app(OperatorPayoutService::class)->summary($operator);
        $request->refresh();
        $this->assertSame(PayoutRequest::POST_EXECUTION_STATE_COMPLETED_AWAITING_SETTLEMENT, $request->post_execution_state);
        $this->assertNull($request->post_execution_handed_off_at);

        $checkedAt = optional($attempt->provider_state_checked_at)?->toDateTimeString();

        $this->postJson("/api/paymongo/payout-executions/{$attempt->id}/callback", $payload, [
            'Paymongo-Signature' => $signature,
        ])->assertOk();

        $attempt->refresh();
        $this->assertSame(PayoutExecutionAttempt::STATE_COMPLETED, $attempt->execution_state);
        $this->assertSame($checkedAt, optional($attempt->provider_state_checked_at)?->toDateTimeString());
    }

    public function test_late_provider_return_after_completion_stays_explicit_and_does_not_create_settlement(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        [, $operator] = $this->createApprovedOperator('operator-paymongo-returned@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay', 'bic' => 'TESTPHM2XXX'],
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_COMPLETED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'external_reference' => 'wallet_tr_test_returned_001',
            'provider_name' => 'paymongo',
            'provider_state' => 'succeeded',
            'provider_state_source' => 'callback',
            'provider_state_checked_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => User::factory()->create(['is_admin' => true])->id,
        ]);

        $payload = [
            'data' => [
                'id' => 'wallet_tr_test_returned_001',
                'type' => 'wallet_transaction',
                'attributes' => [
                    'status' => 'returned',
                    'status_message' => 'Destination details are incomplete.',
                    'reference_number' => 'PM-RETURN-001',
                ],
            ],
        ];

        $signature = $this->signPayMongoPayload(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $this->postJson("/api/paymongo/payout-executions/{$attempt->id}/callback", $payload, [
            'Paymongo-Signature' => $signature,
        ])->assertOk();

        app(OperatorPayoutService::class)->summary($operator);

        $attempt->refresh();
        $request->refresh();

        $this->assertSame(PayoutExecutionAttempt::STATE_RETRYABLE_FAILED, $attempt->execution_state);
        $this->assertSame(PayoutExecutionAttempt::PROVIDER_STATE_RETURNED, $attempt->provider_state);
        $this->assertSame(PayoutRequest::STATUS_APPROVED, $request->status);
        $this->assertSame(PayoutRequest::SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW, $request->settlement_state);
        $this->assertSame(PayoutRequest::SETTLEMENT_BLOCK_PROVIDER_NEGATIVE_OUTCOME, $request->settlement_block_reason);
        $this->assertSame(PayoutRequest::POST_EXECUTION_STATE_PROVIDER_RETURNED, $request->post_execution_state);
        $this->assertSame('provider_returned_under_review', $request->provider_status);
        $this->assertDatabaseCount('payout_settlements', 0);
        $this->assertDatabaseCount('payout_settlement_corrections', 0);

        $checkedAt = optional($attempt->provider_state_checked_at)?->toDateTimeString();

        $this->postJson("/api/paymongo/payout-executions/{$attempt->id}/callback", $payload, [
            'Paymongo-Signature' => $signature,
        ])->assertOk();

        $attempt->refresh();
        $this->assertSame($checkedAt, optional($attempt->provider_state_checked_at)?->toDateTimeString());

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get('/admin/payout-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payoutRequests.0.post_execution_state', PayoutRequest::POST_EXECUTION_STATE_PROVIDER_RETURNED));
    }

    public function test_completed_execution_requires_explicit_settlement_handoff_before_manual_settlement(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-handoff@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay'],
        ]);

        PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_COMPLETED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'provider_name' => 'paymongo',
            'provider_state' => 'succeeded',
            'provider_state_source' => 'callback',
            'provider_state_checked_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => $admin->id,
        ]);

        app(OperatorPayoutService::class)->summary($operator);
        $request->refresh();

        $this->assertSame(PayoutRequest::POST_EXECUTION_STATE_COMPLETED_AWAITING_SETTLEMENT, $request->post_execution_state);
        $this->assertNull($request->post_execution_handed_off_at);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/settle", [
                'amount' => 300,
                'settlement_reference' => 'SETTLE-WITHOUT-HANDOFF',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Manual settlement is blocked until the completed payout execution is explicitly handed off for settlement review.');

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/confirm-settlement-handoff", [
                'reason' => 'Provider transfer completed and internal settlement review can proceed.',
                'notes' => 'Verified completed provider execution before settlement.',
            ])
            ->assertRedirect('/admin/payout-requests');

        $request->refresh();

        $this->assertNotNull($request->post_execution_handed_off_at);
        $this->assertDatabaseHas('payout_post_execution_events', [
            'payout_request_id' => $request->id,
            'event_type' => 'settlement_handoff_confirmed',
            'resulting_post_execution_state' => PayoutRequest::POST_EXECUTION_STATE_COMPLETED_AWAITING_SETTLEMENT,
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/settle", [
                'amount' => 300,
                'settlement_reference' => 'HANDOFF-SETTLE-001',
            ])
            ->assertRedirect('/admin/payout-requests');

        $this->assertDatabaseHas('payout_settlements', [
            'payout_request_id' => $request->id,
            'settlement_reference' => 'HANDOFF-SETTLE-001',
        ]);
    }

    public function test_duplicate_completed_updates_do_not_create_duplicate_post_execution_state_events(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        [, $operator] = $this->createApprovedOperator('operator-duplicate-completed-state@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_COMPLETED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'external_reference' => 'wallet_tr_postexec_001',
            'provider_name' => 'paymongo',
            'provider_state' => 'succeeded',
            'provider_state_source' => 'callback',
            'provider_state_checked_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => User::factory()->create(['is_admin' => true])->id,
        ]);

        app(OperatorPayoutService::class)->summary($operator);
        app(OperatorPayoutService::class)->summary($operator);

        $this->assertDatabaseCount('payout_post_execution_events', 1);
        $this->assertDatabaseHas('payout_post_execution_events', [
            'payout_request_id' => $request->id,
            'payout_execution_attempt_id' => $attempt->id,
            'event_type' => 'state_sync',
            'resulting_post_execution_state' => PayoutRequest::POST_EXECUTION_STATE_COMPLETED_AWAITING_SETTLEMENT,
        ]);
    }

    public function test_manual_followup_and_terminal_failed_execution_states_surface_as_post_execution_exceptions(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [$user, $operator] = $this->createApprovedOperator('operator-post-exception-view@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $manualFollowup = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 200,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinutes(2),
            'reviewed_at' => now()->subMinutes(2),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ]);

        PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $manualFollowup->id,
            'operator_id' => $operator->id,
            'amount' => 200,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
            'execution_reference' => 'PXR-'.$manualFollowup->id.'-01',
            'idempotency_key' => 'payout-execution:'.$manualFollowup->id.':01',
            'provider_name' => 'manual',
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => $admin->id,
        ]);

        $terminalFailed = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 210,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ]);

        PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $terminalFailed->id,
            'operator_id' => $operator->id,
            'amount' => 210,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_TERMINAL_FAILED,
            'execution_reference' => 'PXR-'.$terminalFailed->id.'-01',
            'idempotency_key' => 'payout-execution:'.$terminalFailed->id.':01',
            'provider_name' => 'manual',
            'completed_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => $admin->id,
        ]);

        app(OperatorPayoutService::class)->summary($operator);

        $manualFollowup->refresh();
        $terminalFailed->refresh();

        $this->assertSame(PayoutRequest::POST_EXECUTION_STATE_MANUAL_FOLLOWUP_REQUIRED, $manualFollowup->post_execution_state);
        $this->assertSame(PayoutRequest::POST_EXECUTION_STATE_TERMINAL_FAILED, $terminalFailed->post_execution_state);

        $this->actingAs($admin)
            ->get('/admin/payout-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payoutRequests.0.post_execution_state', PayoutRequest::POST_EXECUTION_STATE_TERMINAL_FAILED)
                ->where('payoutRequests.1.post_execution_state', PayoutRequest::POST_EXECUTION_STATE_MANUAL_FOLLOWUP_REQUIRED));

        $this->actingAs($user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingRequests.0.post_execution_state', PayoutRequest::POST_EXECUTION_STATE_TERMINAL_FAILED)
                ->where('pendingRequests.1.post_execution_state', PayoutRequest::POST_EXECUTION_STATE_MANUAL_FOLLOWUP_REQUIRED));
    }

    public function test_settlement_handoff_is_blocked_when_readiness_is_unhealthy(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-handoff-blocked@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ]);

        PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_COMPLETED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'provider_name' => 'paymongo',
            'provider_state' => 'succeeded',
            'provider_state_source' => 'callback',
            'provider_state_checked_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/confirm-settlement-handoff", [
                'reason' => 'Ready for settlement review',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Payout settlement is blocked because AP sync, AP health reconcile, or billing-post automation is unhealthy.');

        $this->assertDatabaseCount('payout_post_execution_events', 0);
    }

    public function test_provider_reversal_after_settlement_links_into_safe_settlement_correction_workflow(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-provider-reversal-settled@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_SETTLED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_SETTLED,
            'requested_at' => now()->subMinutes(3),
            'reviewed_at' => now()->subMinutes(2),
            'reviewed_by_user_id' => $admin->id,
            'paid_at' => now()->subMinute(),
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay', 'bic' => 'TESTPHM2XXX'],
        ]);

        $settlement = PayoutSettlement::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'settled_at' => now()->subMinute(),
            'settled_by_user_id' => $admin->id,
            'settlement_reference' => 'PAYMONGO-SETTLED-001',
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'payout_settlement_id' => $settlement->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_COMPLETED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'external_reference' => 'wallet_tr_test_reversed_001',
            'provider_name' => 'paymongo',
            'provider_state' => 'succeeded',
            'provider_state_source' => 'callback',
            'provider_state_checked_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => $admin->id,
        ]);

        $payload = [
            'data' => [
                'id' => 'wallet_tr_test_reversed_001',
                'type' => 'wallet_transaction',
                'attributes' => [
                    'status' => 'reversed',
                    'status_message' => 'Transfer reversed after review.',
                    'reference_number' => 'PM-REV-001',
                ],
            ],
        ];

        $this->postJson("/api/paymongo/payout-executions/{$attempt->id}/callback", $payload, [
            'Paymongo-Signature' => $this->signPayMongoPayload(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ])->assertOk();

        app(OperatorPayoutService::class)->summary($operator);

        $request->refresh()->load('settlement.correction');
        $attempt->refresh();
        $summary = app(OperatorPayoutService::class)->summary($operator);

        $this->assertSame(PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED, $attempt->execution_state);
        $this->assertSame(PayoutExecutionAttempt::PROVIDER_STATE_REVERSED, $attempt->provider_state);
        $this->assertSame(PayoutRequest::STATUS_REVIEW_REQUIRED, $request->status);
        $this->assertSame(PayoutRequest::SETTLEMENT_STATE_REVERSED, $request->settlement_state);
        $this->assertSame(PayoutRequest::SETTLEMENT_BLOCK_REVERSED, $request->settlement_block_reason);
        $this->assertSame(PayoutRequest::POST_EXECUTION_STATE_PROVIDER_REVERSED, $request->post_execution_state);
        $this->assertNotNull($request->settlement);
        $this->assertNotNull($request->settlement->correction);
        $this->assertSame(PayoutSettlementCorrection::TYPE_PROVIDER_REVERSAL, $request->settlement->correction->correction_type);
        $this->assertStringContainsString('reversed', $request->settlement->correction->reason);
        $this->assertDatabaseHas('payout_post_execution_events', [
            'payout_request_id' => $request->id,
            'event_type' => 'provider_negative_outcome_linked',
            'resulting_post_execution_state' => PayoutRequest::POST_EXECUTION_STATE_PROVIDER_REVERSED,
        ]);
        $this->assertSame(0.0, $summary['paid_out']);
        $this->assertSame(300.0, $summary['review_required_reserved']);

        $this->actingAs($admin)
            ->get('/admin/payout-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payoutRequests.0.post_execution_state', PayoutRequest::POST_EXECUTION_STATE_PROVIDER_REVERSED));

        $this->actingAs($operator->user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingRequests.0.post_execution_state', PayoutRequest::POST_EXECUTION_STATE_PROVIDER_REVERSED));
    }

    public function test_live_paymongo_dispatch_is_blocked_when_rollout_is_disabled(): void
    {
        $this->noteFreshAutomation();
        config()->set('payouts.execution_provider', 'paymongo');
        config()->set('payouts.providers.paymongo.enabled', true);
        config()->set('payouts.providers.paymongo.live_execution_enabled', false);
        config()->set('services.paymongo.payouts_enabled', true);
        config()->set('services.paymongo.secret_key', 'sk_live_123');
        config()->set('services.paymongo.payout_wallet_id', 'wallet_live_123');
        config()->set('services.paymongo.payout_webhook_secret', 'whsec_live_123');
        config()->set('services.paymongo.payout_callback_url', 'https://portal.example.com');
        config()->set('services.paymongo.base_url', 'https://api.paymongo.test/v1');
        config()->set('app.url', 'https://portal.example.com');

        $admin = User::factory()->create(['is_admin' => true]);
        [$user, $operator] = $this->createApprovedOperator('operator-paymongo-live-blocked@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay', 'bic' => 'TESTPHM2XXX'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'paymongo',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'PayMongo payout execution is blocked because live rollout is disabled for this environment.');

        $this->actingAs($admin)
            ->get('/admin/payout-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('providerOps.provider', 'paymongo')
                ->where('providerOps.provider_mode', 'live')
                ->where('providerOps.live_execution_enabled', false)
                ->where('providerOps.provider_readiness.blocking_reason', 'PayMongo payout execution is blocked because live rollout is disabled for this environment.')
                ->where('payoutRequests.0.execution_preflight.blocking_reason', 'PayMongo payout execution is blocked because live rollout is disabled for this environment.'));

        $this->actingAs($user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('providerOps.provider', 'paymongo')
                ->where('providerOps.provider_mode', 'live')
                ->where('providerOps.live_execution_enabled', false)
                ->where('pendingRequests.0.execution_preflight.blocking_reason', 'PayMongo payout execution is blocked because live rollout is disabled for this environment.'));
    }

    public function test_unverifiable_paymongo_callback_is_rejected_safely(): void
    {
        $this->configurePayMongoExecution();
        [, $operator] = $this->createApprovedOperator('operator-paymongo-invalid-callback@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_DISPATCHED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'external_reference' => 'wallet_tr_test_bad_001',
            'provider_name' => 'paymongo',
            'provider_state' => 'pending',
            'provider_state_source' => 'dispatch',
            'provider_state_checked_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => User::factory()->create(['is_admin' => true])->id,
        ]);

        $this->postJson("/api/paymongo/payout-executions/{$attempt->id}/callback", [
            'data' => [
                'id' => 'wallet_tr_test_bad_001',
                'attributes' => ['status' => 'succeeded'],
            ],
        ], [
            'Paymongo-Signature' => 't=1,te=bad',
        ])->assertStatus(400);

        $attempt->refresh();
        $this->assertSame(PayoutExecutionAttempt::STATE_DISPATCHED, $attempt->execution_state);
        $this->assertSame('pending', $attempt->provider_state);
    }

    public function test_paymongo_poll_reconcile_updates_execution_state_safely(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-paymongo-poll@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_DISPATCHED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'external_reference' => 'wallet_tr_test_poll_001',
            'provider_name' => 'paymongo',
            'provider_state' => 'pending',
            'provider_state_source' => 'dispatch',
            'provider_state_checked_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => $admin->id,
        ]);

        Http::fake([
            'https://api.paymongo.test/v1/wallets/wallet_test_123/transactions/wallet_tr_test_poll_001' => Http::response([
                'data' => [
                    'id' => 'wallet_tr_test_poll_001',
                    'attributes' => [
                        'status' => 'failed',
                        'provider_error' => 'Account number invalid.',
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/reconcile")
            ->assertRedirect('/admin/payout-requests');

        $attempt->refresh();

        $this->assertSame(PayoutExecutionAttempt::STATE_RETRYABLE_FAILED, $attempt->execution_state);
        $this->assertSame('failed', $attempt->provider_state);
        $this->assertSame('poll', $attempt->provider_state_source);
        $this->assertSame('Account number invalid.', $attempt->last_error);
        $this->assertDatabaseCount('payout_settlements', 0);
    }

    public function test_late_paymongo_poll_return_after_callback_completion_is_handled_safely(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-paymongo-poll-returned@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay', 'bic' => 'TESTPHM2XXX'],
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_COMPLETED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'external_reference' => 'wallet_tr_test_poll_return_001',
            'provider_name' => 'paymongo',
            'provider_state' => 'succeeded',
            'provider_state_source' => 'callback',
            'provider_state_checked_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => $admin->id,
        ]);

        Http::fake([
            'https://api.paymongo.test/v1/wallets/wallet_test_123/transactions/wallet_tr_test_poll_return_001' => Http::response([
                'data' => [
                    'id' => 'wallet_tr_test_poll_return_001',
                    'attributes' => [
                        'status' => 'returned',
                        'status_message' => 'Account details were rejected by the destination bank.',
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/reconcile")
            ->assertRedirect('/admin/payout-requests');

        app(OperatorPayoutService::class)->summary($operator);

        $attempt->refresh();
        $request->refresh();

        $this->assertSame(PayoutExecutionAttempt::STATE_RETRYABLE_FAILED, $attempt->execution_state);
        $this->assertSame(PayoutExecutionAttempt::PROVIDER_STATE_RETURNED, $attempt->provider_state);
        $this->assertSame(PayoutRequest::POST_EXECUTION_STATE_PROVIDER_RETURNED, $request->post_execution_state);
        $this->assertSame(PayoutRequest::SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW, $request->settlement_state);
        $this->assertSame(PayoutRequest::SETTLEMENT_BLOCK_PROVIDER_NEGATIVE_OUTCOME, $request->settlement_block_reason);
    }

    public function test_duplicate_paymongo_poll_result_no_ops_safely(): void
    {
        $this->noteFreshAutomation();
        $this->configurePayMongoExecution();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-paymongo-poll-duplicate@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_DISPATCHED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'external_reference' => 'wallet_tr_test_poll_dup_001',
            'provider_name' => 'paymongo',
            'provider_state' => 'pending',
            'provider_state_source' => 'dispatch',
            'provider_state_checked_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => $admin->id,
        ]);

        Http::fake([
            'https://api.paymongo.test/v1/wallets/wallet_test_123/transactions/wallet_tr_test_poll_dup_001' => Http::response([
                'data' => [
                    'id' => 'wallet_tr_test_poll_dup_001',
                    'attributes' => [
                        'status' => 'pending',
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/reconcile")
            ->assertRedirect('/admin/payout-requests');

        $attempt->refresh();
        $checkedAt = optional($attempt->provider_state_checked_at)?->toDateTimeString();

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/reconcile")
            ->assertRedirect('/admin/payout-requests');

        $attempt->refresh();

        $this->assertSame(PayoutExecutionAttempt::STATE_DISPATCHED, $attempt->execution_state);
        $this->assertSame($checkedAt, optional($attempt->provider_state_checked_at)?->toDateTimeString());
    }

    public function test_ambiguous_provider_state_remains_support_visible_and_does_not_corrupt_settlement_truth(): void
    {
        $this->configurePayMongoExecution();
        [, $operator] = $this->createApprovedOperator('operator-paymongo-ambiguous@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_COMPLETED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'external_reference' => 'wallet_tr_test_amb_001',
            'provider_name' => 'paymongo',
            'provider_state' => 'succeeded',
            'provider_state_source' => 'callback',
            'provider_state_checked_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => User::factory()->create(['is_admin' => true])->id,
        ]);

        $payload = [
            'data' => [
                'id' => 'wallet_tr_test_amb_001',
                'type' => 'wallet_transaction',
                'attributes' => [
                    'status' => 'pending',
                ],
            ],
        ];

        $this->postJson("/api/paymongo/payout-executions/{$attempt->id}/callback", $payload, [
            'Paymongo-Signature' => $this->signPayMongoPayload(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ])->assertOk();

        $attempt->refresh();
        $request->refresh();

        $this->assertSame(PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED, $attempt->execution_state);
        $this->assertSame('pending', $attempt->provider_state);
        $this->assertStringContainsString('Provider state conflict detected', (string) $attempt->last_error);
        $this->assertSame(PayoutRequest::STATUS_APPROVED, $request->status);
        $this->assertDatabaseCount('payout_settlements', 0);
    }

    public function test_stale_execution_attempt_becomes_support_visible_and_requires_explicit_reconcile_and_retry(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();
        config()->set('payouts.execution.manual_followup_stale_minutes', 1);

        $admin = User::factory()->create(['is_admin' => true]);
        [$user, $operator] = $this->createApprovedOperator('operator-exec-stale@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinutes(10),
            'reviewed_at' => now()->subMinutes(9),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'triggered_at' => now()->subMinutes(5),
            'triggered_by_user_id' => $admin->id,
            'provider_name' => 'manual',
            'provider_request_metadata' => ['stub' => true],
            'provider_response_metadata' => ['message' => 'existing'],
        ]);

        $this->actingAs($admin)
            ->get('/admin/payout-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payoutRequests.0.latest_execution_attempt.execution_state', PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED)
                ->where('payoutRequests.0.latest_execution_attempt.is_stale', true));

        $this->actingAs($user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingRequests.0.latest_execution_attempt.is_stale', true));

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'manual',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'This payout request already has an active execution attempt.');

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/reconcile")
            ->assertRedirect('/admin/payout-requests');

        $attempt->refresh();

        $this->assertSame(PayoutExecutionAttempt::STATE_RETRYABLE_FAILED, $attempt->execution_state);
        $this->assertNotNull($attempt->stale_at);
        $this->assertNotNull($attempt->completed_at);
        $this->assertDatabaseHas('payout_execution_attempt_resolutions', [
            'payout_execution_attempt_id' => $attempt->id,
            'resolution_type' => PayoutExecutionAttemptResolution::TYPE_RECONCILED_STALE,
            'resulting_state' => PayoutExecutionAttempt::STATE_RETRYABLE_FAILED,
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'manual',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'This payout request already has execution history. Use the explicit execution retry or resolution path instead.');
    }

    public function test_safe_retry_creates_new_execution_attempt_with_parent_lineage_and_blocks_duplicate_retry(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-exec-retry@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinutes(10),
            'reviewed_at' => now()->subMinutes(9),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_RETRYABLE_FAILED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'triggered_at' => now()->subMinutes(5),
            'completed_at' => now()->subMinutes(4),
            'last_error' => 'Manual follow-up timed out.',
            'triggered_by_user_id' => $admin->id,
            'provider_name' => 'manual',
            'provider_request_metadata' => ['stub' => true],
            'provider_response_metadata' => ['message' => 'existing'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/retry", [
                'reason' => 'Operator confirmed payout still needs a fresh execution attempt.',
                'notes' => 'Re-run through the manual execution stub.',
                'provider' => 'manual',
            ])
            ->assertRedirect('/admin/payout-requests');

        $request->refresh()->load('latestExecutionAttempt.latestResolution');
        $attempt->refresh();

        $this->assertSame(2, $request->executionAttempts()->count());
        $this->assertSame(PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED, $request->latestExecutionAttempt->execution_state);
        $this->assertSame($attempt->id, $request->latestExecutionAttempt->parent_attempt_id);
        $this->assertSame('PXR-'.$request->id.'-02', $request->latestExecutionAttempt->execution_reference);
        $this->assertDatabaseHas('payout_execution_attempt_resolutions', [
            'payout_execution_attempt_id' => $attempt->id,
            'resolution_type' => PayoutExecutionAttemptResolution::TYPE_RETRIED,
            'resulting_state' => PayoutExecutionAttempt::STATE_RETRYABLE_FAILED,
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/retry", [
                'reason' => 'Try again immediately',
                'provider' => 'manual',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Only the latest execution attempt can be retried.');
    }

    public function test_execution_attempt_can_be_marked_completed_or_terminal_failed_without_corrupting_settlement_truth(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-exec-resolve@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 200,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinutes(10),
            'reviewed_at' => now()->subMinutes(9),
            'reviewed_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ]);

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 200,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'triggered_at' => now()->subMinutes(5),
            'triggered_by_user_id' => $admin->id,
            'provider_name' => 'manual',
            'provider_request_metadata' => ['stub' => true],
            'provider_response_metadata' => ['message' => 'existing'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/mark-completed", [
                'reason' => 'Manual bank portal confirmed the transfer was initiated successfully.',
                'notes' => 'Still waiting for explicit settlement record.',
            ])
            ->assertRedirect('/admin/payout-requests');

        $attempt->refresh();
        $request->refresh();

        $this->assertSame(PayoutExecutionAttempt::STATE_COMPLETED, $attempt->execution_state);
        $this->assertNull($request->settlement);
        $this->assertSame(PayoutRequest::STATUS_APPROVED, $request->status);
        $this->assertDatabaseHas('payout_execution_attempt_resolutions', [
            'payout_execution_attempt_id' => $attempt->id,
            'resolution_type' => PayoutExecutionAttemptResolution::TYPE_MARKED_COMPLETED,
            'resulting_state' => PayoutExecutionAttempt::STATE_COMPLETED,
        ]);

        $retryable = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 200,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_RETRYABLE_FAILED,
            'execution_reference' => 'PXR-'.$request->id.'-02',
            'idempotency_key' => 'payout-execution:'.$request->id.':02',
            'triggered_at' => now()->subMinutes(3),
            'triggered_by_user_id' => $admin->id,
            'provider_name' => 'manual',
            'provider_request_metadata' => ['stub' => true],
            'provider_response_metadata' => ['message' => 'existing'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$retryable->id}/mark-terminal-failed", [
                'reason' => 'Bank portal rejected the payout details.',
                'notes' => 'Needs manual correction before any future retry.',
            ])
            ->assertRedirect('/admin/payout-requests');

        $retryable->refresh();
        $summary = app(OperatorPayoutService::class)->summary($operator);

        $this->assertSame(PayoutExecutionAttempt::STATE_TERMINAL_FAILED, $retryable->execution_state);
        $this->assertSame('Bank portal rejected the payout details.', $retryable->last_error);
        $this->assertSame(0, $summary['execution_in_flight_count']);
        $this->assertDatabaseHas('payout_execution_attempt_resolutions', [
            'payout_execution_attempt_id' => $retryable->id,
            'resolution_type' => PayoutExecutionAttemptResolution::TYPE_MARKED_TERMINAL_FAILED,
            'resulting_state' => PayoutExecutionAttempt::STATE_TERMINAL_FAILED,
        ]);
    }

    public function test_retry_and_reconcile_execution_actions_are_blocked_when_readiness_is_unhealthy(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-exec-actions-blocked@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $admin->id,
        ]);

        $active = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'triggered_at' => now()->subMinutes(2),
            'triggered_by_user_id' => $admin->id,
            'provider_name' => 'manual',
            'provider_request_metadata' => ['stub' => true],
            'provider_response_metadata' => ['message' => 'existing'],
        ]);

        $retryable = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_RETRYABLE_FAILED,
            'execution_reference' => 'PXR-'.$request->id.'-02',
            'idempotency_key' => 'payout-execution:'.$request->id.':02',
            'triggered_at' => now()->subMinute(),
            'completed_at' => now()->subMinute(),
            'triggered_by_user_id' => $admin->id,
            'provider_name' => 'manual',
            'provider_request_metadata' => ['stub' => true],
            'provider_response_metadata' => ['message' => 'existing'],
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$active->id}/reconcile")
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Payout execution is blocked because AP sync, AP health reconcile, or billing-post automation is unhealthy.');

        $this->actingAs($admin)
            ->post("/admin/payout-execution-attempts/{$retryable->id}/retry", [
                'reason' => 'Not safe',
                'provider' => 'manual',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Payout execution is blocked because AP sync, AP health reconcile, or billing-post automation is unhealthy.');
    }

    public function test_settled_payout_can_be_reversed_exactly_once_and_moves_to_review_required(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [$user, $operator] = $this->createApprovedOperator('operator-reverse@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_SETTLED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_SETTLED,
            'requested_at' => now()->subMinutes(3),
            'reviewed_at' => now()->subMinutes(2),
            'reviewed_by_user_id' => $admin->id,
            'paid_at' => now()->subMinute(),
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ]);

        $settlement = PayoutSettlement::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'settled_at' => now()->subMinute(),
            'settled_by_user_id' => $admin->id,
            'settlement_reference' => 'MANUAL-SETTLED-001',
            'notes' => 'Settled manually.',
        ]);

        $before = app(OperatorPayoutService::class)->summary($operator);
        $this->assertSame(300.0, $before['paid_out']);
        $this->assertSame(0.0, $before['reserved_for_payout']);
        $this->assertSame(200.0, $before['requestable_balance']);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/reverse-settlement", [
                'reason' => 'Mistaken settlement record',
                'notes' => 'No actual transfer was completed.',
            ])
            ->assertRedirect('/admin/payout-requests');

        $request->refresh();
        $request->load('settlement.correction');
        $after = app(OperatorPayoutService::class)->summary($operator);

        $this->assertSame(PayoutRequest::STATUS_REVIEW_REQUIRED, $request->status);
        $this->assertSame(PayoutRequest::SETTLEMENT_STATE_REVERSED, $request->settlement_state);
        $this->assertSame(PayoutRequest::SETTLEMENT_BLOCK_REVERSED, $request->settlement_block_reason);
        $this->assertNotNull($request->invalidated_at);
        $this->assertSame($admin->id, $request->invalidated_by_user_id);
        $this->assertNotNull($request->settlement);
        $this->assertSame($settlement->id, $request->settlement->id);
        $this->assertNotNull($request->settlement->correction);
        $this->assertSame(PayoutSettlementCorrection::TYPE_REVERSAL, $request->settlement->correction->correction_type);
        $this->assertSame('Mistaken settlement record', $request->settlement->correction->reason);
        $this->assertDatabaseCount('payout_settlements', 1);
        $this->assertDatabaseCount('payout_settlement_corrections', 1);
        $this->assertDatabaseCount('billing_ledger_entries', 1);
        $this->assertSame(0.0, $after['paid_out']);
        $this->assertSame(300.0, $after['review_required_reserved']);
        $this->assertSame(300.0, $after['reserved_for_payout']);
        $this->assertSame(200.0, $after['requestable_balance']);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/reverse-settlement", [
                'reason' => 'Second try',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Only settled payout requests can have their settlement reversed.');

        $this->actingAs($user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.review_required_reserved', '300.00')
                ->where('pendingRequests.0.status', PayoutRequest::STATUS_REVIEW_REQUIRED)
                ->where('pendingRequests.0.settlement.correction.reason', 'Mistaken settlement record'));

        $this->actingAs($admin)
            ->get('/admin/payout-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payoutRequests.0.status', PayoutRequest::STATUS_REVIEW_REQUIRED)
                ->where('payoutRequests.0.settlement.correction.reason', 'Mistaken settlement record'));
    }

    public function test_review_required_request_can_be_cancelled_and_released_safely(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-release-review@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = $this->createReviewRequiredRequest($operator, $admin, 300, 'REV-REL-001');

        $before = app(OperatorPayoutService::class)->summary($operator);
        $this->assertSame(300.0, $before['review_required_reserved']);
        $this->assertSame(200.0, $before['requestable_balance']);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/cancel-and-release", [
                'reason' => 'Settlement voided and request closed',
                'notes' => 'Release the hold back to operator balance.',
            ])
            ->assertRedirect('/admin/payout-requests');

        $request->refresh();
        $request->load(['settlement.correction', 'latestResolution']);
        $after = app(OperatorPayoutService::class)->summary($operator);

        $this->assertSame(PayoutRequest::STATUS_CANCELLED, $request->status);
        $this->assertSame(PayoutRequest::SETTLEMENT_STATE_REVERSED, $request->settlement_state);
        $this->assertSame(PayoutRequest::SETTLEMENT_BLOCK_REVERSED, $request->settlement_block_reason);
        $this->assertSame('Settlement voided and request closed', $request->cancellation_reason);
        $this->assertNotNull($request->settlement);
        $this->assertNotNull($request->settlement->correction);
        $this->assertSame(PayoutRequestResolution::TYPE_CANCEL_AND_RELEASE, $request->latestResolution->resolution_type);
        $this->assertSame(0.0, $after['review_required_reserved']);
        $this->assertSame(0.0, $after['reserved_for_payout']);
        $this->assertSame(500.0, $after['requestable_balance']);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/cancel-and-release", [
                'reason' => 'Second try',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Only review-required payout requests can be resolved through this path.');
    }

    public function test_review_required_request_cannot_be_returned_to_review_when_support_is_insufficient(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-review-insufficient@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = $this->createReviewRequiredRequest($operator, $admin, 300, 'REV-INSUFF-001');

        PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 250,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/resolve-return-to-review", [
                'reason' => 'Try to reopen',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Return-to-review is blocked because the payout amount is no longer fully supported by current operator balance.');

        $request->refresh();
        $this->assertSame(PayoutRequest::STATUS_REVIEW_REQUIRED, $request->status);
        $this->assertDatabaseCount('payout_request_resolutions', 0);
    }

    public function test_review_required_request_can_be_returned_to_review_when_support_is_healthy(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [$user, $operator] = $this->createApprovedOperator('operator-review-healthy@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = $this->createReviewRequiredRequest($operator, $admin, 300, 'REV-OK-001');

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/resolve-return-to-review", [
                'reason' => 'Reopen for fresh admin review',
                'notes' => 'Balance and readiness look good again.',
            ])
            ->assertRedirect('/admin/payout-requests');

        $request->refresh();
        $request->load(['settlement.correction', 'latestResolution']);
        $summary = app(OperatorPayoutService::class)->summary($operator);

        $this->assertSame(PayoutRequest::STATUS_PENDING_REVIEW, $request->status);
        $this->assertSame(PayoutRequest::SETTLEMENT_STATE_NOT_READY, $request->settlement_state);
        $this->assertNull($request->settlement_block_reason);
        $this->assertSame(PayoutRequestResolution::TYPE_RETURN_TO_REVIEW, $request->latestResolution->resolution_type);
        $this->assertSame(300.0, $summary['pending_review_reserved']);
        $this->assertSame(0.0, $summary['review_required_reserved']);
        $this->assertSame(300.0, $summary['reserved_for_payout']);
        $this->assertSame(200.0, $summary['requestable_balance']);
        $this->assertNotNull($request->settlement);
        $this->assertNotNull($request->settlement->correction);

        $this->actingAs($user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingRequests.0.status', PayoutRequest::STATUS_PENDING_REVIEW)
                ->where('pendingRequests.0.latest_resolution.resolution_type', PayoutRequestResolution::TYPE_RETURN_TO_REVIEW));
    }

    public function test_non_ready_or_terminal_requests_cannot_be_settled(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-settle-blocked@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $pendingReview = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_PENDING_REVIEW,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_NOT_READY,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$pendingReview->id}/settle", [
                'amount' => 125,
                'settlement_reference' => 'REF-INVALID',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Only approved payout requests can be settled.');

        $cancelled = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_CANCELLED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_NOT_READY,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$cancelled->id}/settle", [
                'amount' => 125,
                'settlement_reference' => 'REF-CANCELLED',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Only approved payout requests can be settled.');

        $this->assertDatabaseCount('payout_settlements', 0);
    }

    public function test_partial_settlement_is_rejected_clearly(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-partial@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 200,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/settle", [
                'amount' => 150,
                'settlement_reference' => 'PARTIAL-NO',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'This phase only supports exact full settlement. Partial settlement is not allowed.');

        $this->assertDatabaseCount('payout_settlements', 0);
        $this->assertDatabaseHas('payout_requests', [
            'id' => $request->id,
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
        ]);
    }

    public function test_settlement_reversal_is_blocked_when_readiness_is_unhealthy(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-reversal-blocked@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 200,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_SETTLED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_SETTLED,
            'requested_at' => now(),
            'paid_at' => now(),
        ]);

        PayoutSettlement::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 200,
            'currency' => 'PHP',
            'settled_at' => now(),
            'settled_by_user_id' => $admin->id,
            'settlement_reference' => 'BLOCKED-REVERSAL',
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/reverse-settlement", [
                'reason' => 'Need correction',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Payout settlement is blocked because AP sync, AP health reconcile, or billing-post automation is unhealthy.');

        $this->assertDatabaseCount('payout_settlement_corrections', 0);
    }

    public function test_approved_request_is_invalidated_when_reversal_removes_supporting_balance(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-invalidate@example.com');
        [, $accessPoint] = $this->createLedgerBackedAccessPoint($operator);

        $payoutRequest = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
        ]);

        $debit = BillingLedgerEntry::query()->where('operator_id', $operator->id)->sole();
        $debit->forceFill([
            'state' => BillingLedgerEntry::STATE_REVERSED,
            'voided_at' => now(),
        ])->save();

        BillingLedgerEntry::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $debit->site_id,
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

        $summary = app(OperatorPayoutService::class)->summary($operator);
        $payoutRequest->refresh();

        $this->assertSame(0.0, $summary['net_payable_fees']);
        $this->assertSame(PayoutRequest::SETTLEMENT_STATE_BLOCKED_UNDERFUNDED, $payoutRequest->settlement_state);
        $this->assertSame(PayoutRequest::SETTLEMENT_BLOCK_UNDERFUNDED, $payoutRequest->settlement_block_reason);
        $this->assertNotNull($payoutRequest->invalidated_at);
        $this->assertDatabaseCount('billing_ledger_entries', 2);
    }

    public function test_ownership_correction_can_invalidate_approved_request_safely(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-corrected@example.com');
        [, $accessPoint] = $this->createLedgerBackedAccessPoint($operator);

        $payoutRequest = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 200,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
        ]);

        $accessPoint->forceFill([
            'billing_state' => AccessPoint::BILLING_STATE_BLOCKED,
            'billing_incident_state' => AccessPoint::BILLING_INCIDENT_CORRECTED_AFTER_BILLING,
        ])->save();

        $summary = app(OperatorPayoutService::class)->summary($operator);
        $payoutRequest->refresh();

        $this->assertSame(500.0, $summary['blocked_fees']);
        $this->assertSame(PayoutRequest::SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW, $payoutRequest->settlement_state);
        $this->assertSame(PayoutRequest::SETTLEMENT_BLOCK_CONFIDENCE_DEGRADED, $payoutRequest->settlement_block_reason);
    }

    public function test_invalidated_request_can_be_cancelled_or_returned_to_review_safely(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-rereview@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 300,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_BLOCKED_UNDERFUNDED,
            'settlement_block_reason' => PayoutRequest::SETTLEMENT_BLOCK_UNDERFUNDED,
            'invalidated_at' => now(),
            'requested_at' => now()->subMinute(),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/return-to-review", [
                'review_notes' => 'Balance recovered, re-review manually.',
            ])
            ->assertRedirect('/admin/payout-requests');

        $request->refresh();
        $this->assertSame(PayoutRequest::STATUS_PENDING_REVIEW, $request->status);
        $this->assertSame(PayoutRequest::SETTLEMENT_STATE_NOT_READY, $request->settlement_state);
        $this->assertNull($request->invalidated_at);

        $request->forceFill([
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_BLOCKED_MANUAL_REVIEW,
            'settlement_block_reason' => PayoutRequest::SETTLEMENT_BLOCK_CONFIDENCE_DEGRADED,
            'invalidated_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/cancel", [
                'review_notes' => 'Unsafe after invalidation.',
            ])
            ->assertRedirect('/admin/payout-requests');

        $this->assertDatabaseHas('payout_requests', [
            'id' => $request->id,
            'status' => PayoutRequest::STATUS_CANCELLED,
            'cancellation_reason' => 'Unsafe after invalidation.',
        ]);
    }

    public function test_legacy_execution_routes_are_blocked_and_manual_settlement_route_is_the_only_supported_path(): void
    {
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-legacy@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/processing")
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Manual payout execution states are disabled until a real settlement phase is implemented.');

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/paid")
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Use the manual settlement action instead. Legacy paid-status transitions are disabled.');

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/failed", [
                'review_notes' => 'No-op',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Manual payout failure states are disabled until a real settlement phase is implemented.');
    }

    public function test_payout_request_creation_is_blocked_when_readiness_is_unhealthy(): void
    {
        [$user, $operator] = $this->createApprovedOperator('operator-blocked@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $this->actingAs($user)
            ->post('/operator/payouts', [
                'amount' => 25,
                'destination_type' => 'bank',
                'destination_account_name' => 'North WiFi',
                'destination_account_reference' => '1234567890',
                'destination_provider' => 'instapay',
            ])
            ->assertSessionHasErrors([
                'amount' => 'Payout request creation is blocked because AP sync, AP health reconcile, or billing-post automation is unhealthy.',
            ]);
    }

    public function test_manual_settlement_is_blocked_when_readiness_is_unhealthy(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator-settlement-blocked@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 200,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_APPROVED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_READY,
            'requested_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/payout-requests/{$request->id}/settle", [
                'amount' => 200,
                'settlement_reference' => 'BLOCKED-REF',
            ])
            ->assertRedirect('/admin/payout-requests')
            ->assertSessionHas('error', 'Payout settlement is blocked because AP sync, AP health reconcile, or billing-post automation is unhealthy.');

        $this->assertDatabaseCount('payout_settlements', 0);
    }

    public function test_operator_and_admin_views_expose_requestable_reserved_and_settlement_context(): void
    {
        $this->withoutVite();
        $this->noteFreshAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [$user, $operator] = $this->createApprovedOperator('operator-view@example.com');
        $this->createLedgerBackedAccessPoint($operator);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_SETTLED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_SETTLED,
            'requested_at' => now(),
            'reviewed_at' => now(),
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
            'destination_snapshot' => ['provider' => 'instapay'],
            'paid_at' => now(),
        ]);
        PayoutSettlement::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'settled_at' => now(),
            'settled_by_user_id' => $admin->id,
            'settlement_reference' => 'SETTLED-001',
            'notes' => 'Settled manually.',
        ]);

        $this->actingAs($user)
            ->get('/operator/payouts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Payouts')
                ->where('summary.net_payable_fees', '500.00')
                ->where('summary.reserved_for_payout', '0.00')
                ->where('summary.requestable_balance', '375.00')
                ->where('summary.settled_total', '125.00')
                ->where('completedRequests.0.status', PayoutRequest::STATUS_SETTLED)
                ->where('completedRequests.0.settlement.settlement_reference', 'SETTLED-001'));

        $this->actingAs($admin)
            ->get('/admin/payout-requests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/PayoutRequests/Index')
                ->where('payoutRequests.0.id', $request->id)
                ->where('payoutRequests.0.financial_context.requestable_balance', '375.00')
                ->where('payoutRequests.0.financial_context.reserved_for_payout', '0.00')
                ->where('payoutRequests.0.settlement_state', PayoutRequest::SETTLEMENT_STATE_SETTLED)
                ->where('payoutRequests.0.settlement.settlement_reference', 'SETTLED-001'));
    }

    public function test_payout_request_routes_are_protected_correctly(): void
    {
        $this->noteFreshAutomation();
        [$user, $operator] = $this->createApprovedOperator('operator-protected@example.com');
        $this->createLedgerBackedAccessPoint($operator);
        $admin = User::factory()->create(['is_admin' => true]);

        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_PENDING_REVIEW,
            'requested_at' => now(),
        ]);

        $this->post('/operator/payouts', [
            'amount' => 25,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ])->assertRedirect('/login');

        $this->actingAs($user)
            ->post("/admin/payout-requests/{$request->id}/approve")
            ->assertForbidden();

        $this->actingAs($user)
            ->post("/admin/payout-requests/{$request->id}/reverse-settlement", [
                'reason' => 'NOPE',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post("/admin/payout-requests/{$request->id}/cancel-and-release", [
                'reason' => 'NOPE',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post("/admin/payout-requests/{$request->id}/resolve-return-to-review", [
                'reason' => 'NOPE',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post("/admin/payout-requests/{$request->id}/trigger-execution", [
                'provider' => 'manual',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post("/admin/payout-requests/{$request->id}/confirm-settlement-handoff", [
                'reason' => 'NOPE',
            ])
            ->assertForbidden();

        $attempt = PayoutExecutionAttempt::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => 125,
            'currency' => 'PHP',
            'execution_state' => PayoutExecutionAttempt::STATE_MANUAL_FOLLOWUP_REQUIRED,
            'execution_reference' => 'PXR-'.$request->id.'-01',
            'idempotency_key' => 'payout-execution:'.$request->id.':01',
            'triggered_at' => now(),
            'triggered_by_user_id' => $admin->id,
        ]);

        $this->actingAs($user)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/reconcile")
            ->assertForbidden();

        $this->actingAs($user)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/retry", [
                'reason' => 'NOPE',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/mark-completed", [
                'reason' => 'NOPE',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post("/admin/payout-execution-attempts/{$attempt->id}/mark-terminal-failed", [
                'reason' => 'NOPE',
            ])
            ->assertForbidden();

        $this->actingAs($user)
            ->post("/admin/payout-requests/{$request->id}/settle", [
                'amount' => 125,
                'settlement_reference' => 'NOPE',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->get('/operator/payouts')
            ->assertForbidden();
    }

    private function createApprovedOperator(string $email = 'operator@example.com'): array
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email' => $email,
        ]);

        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => 'North WiFi',
            'contact_name' => 'North Operator',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_APPROVED,
        ]);

        return [$user, $operator];
    }

    private function createLedgerBackedAccessPoint(Operator $operator): array
    {
        $site = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => 'North Site '.self::$sequence,
            'slug' => 'north-site-'.self::$sequence,
            'omada_site_id' => 'site-'.self::$sequence,
        ]);
        $accessPoint = $this->createOwnedAccessPoint($operator, $site);

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

        return [$site, $accessPoint];
    }

    private function createOwnedAccessPoint(Operator $operator, Site $site): AccessPoint
    {
        $sequence = self::$sequence++;
        $serial = sprintf('SN-PAYOUT-%06d', $sequence);
        $mac = sprintf('AA:CC:EE:%02X:%02X:%02X', ($sequence >> 16) & 0xFF, ($sequence >> 8) & 0xFF, $sequence & 0xFF);
        $admin = User::factory()->create(['is_admin' => true, 'email' => "payout-admin-{$sequence}@example.com"]);

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

        return AccessPoint::query()->create([
            'site_id' => $site->id,
            'claimed_by_operator_id' => $operator->id,
            'approved_claim_id' => $claim->id,
            'serial_number' => $serial,
            'omada_device_id' => 'payout-device-'.$sequence,
            'name' => 'Payout AP '.$sequence,
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
        ]);
    }

    private function createReviewRequiredRequest(Operator $operator, User $admin, float $amount, string $reference): PayoutRequest
    {
        $request = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => $amount,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_REVIEW_REQUIRED,
            'settlement_state' => PayoutRequest::SETTLEMENT_STATE_REVERSED,
            'settlement_block_reason' => PayoutRequest::SETTLEMENT_BLOCK_REVERSED,
            'requested_at' => now()->subMinutes(3),
            'reviewed_at' => now()->subMinutes(2),
            'reviewed_by_user_id' => $admin->id,
            'paid_at' => now()->subMinute(),
            'invalidated_at' => now()->subMinute(),
            'invalidated_by_user_id' => $admin->id,
            'destination_type' => 'bank',
            'destination_account_name' => 'North WiFi',
            'destination_account_reference' => '1234567890',
        ]);

        $settlement = PayoutSettlement::query()->create([
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'amount' => $amount,
            'currency' => 'PHP',
            'settled_at' => now()->subMinute(),
            'settled_by_user_id' => $admin->id,
            'settlement_reference' => $reference,
            'notes' => 'Settled manually.',
        ]);

        PayoutSettlementCorrection::query()->create([
            'payout_settlement_id' => $settlement->id,
            'payout_request_id' => $request->id,
            'operator_id' => $operator->id,
            'correction_type' => PayoutSettlementCorrection::TYPE_REVERSAL,
            'corrected_at' => now()->subSeconds(30),
            'corrected_by_user_id' => $admin->id,
            'reason' => 'Initial reversal',
            'notes' => 'Marked for manual review.',
        ]);

        return $request;
    }

    private function noteFreshAutomation(): void
    {
        $now = now()->toIso8601String();

        Cache::put(AccessPointHealthService::SYNC_HEARTBEAT_CACHE_KEY, $now, now()->addDay());
        Cache::put(AccessPointHealthService::RECONCILE_HEARTBEAT_CACHE_KEY, $now, now()->addDay());
        Cache::put(AccessPointBillingService::POST_HEARTBEAT_CACHE_KEY, $now, now()->addDay());
    }

    private function configurePayMongoExecution(): void
    {
        config()->set('payouts.providers.paymongo.enabled', true);
        config()->set('payouts.providers.paymongo.live_execution_enabled', false);
        config()->set('payouts.execution_provider', 'paymongo');
        config()->set('payouts.providers.paymongo.wallet_id', 'wallet_test_123');
        config()->set('services.paymongo.payouts_enabled', true);
        config()->set('services.paymongo.payout_wallet_id', 'wallet_test_123');
        config()->set('services.paymongo.secret_key', 'sk_test_123');
        config()->set('services.paymongo.base_url', 'https://api.paymongo.test/v1');
        config()->set('services.paymongo.payout_webhook_secret', 'whsec_test_123');
        config()->set('services.paymongo.payout_callback_url', 'https://portal.example.com');
        config()->set('services.paymongo.webhook_tolerance_seconds', 300);
        config()->set('app.url', 'https://portal.example.com');
    }

    private function signPayMongoPayload(string $payload, ?int $timestamp = null): string
    {
        $timestamp ??= now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test_123');

        return "t={$timestamp},te={$signature},li=";
    }
}
