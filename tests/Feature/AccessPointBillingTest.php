<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\AccessPointClaim;
use App\Models\BillingLedgerEntry;
use App\Models\Operator;
use App\Models\Site;
use App\Models\User;
use App\Services\AccessPointBillingService;
use App\Services\AccessPointClaimService;
use App\Services\AccessPointHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class AccessPointBillingTest extends TestCase
{
    use RefreshDatabase;

    private static int $deviceSequence = 1;

    public function test_first_eligible_access_point_posts_exactly_one_php_500_debit(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin);

        $result = app(AccessPointBillingService::class)->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $this->assertSame(1, $result['posted']);
        $this->assertDatabaseCount('billing_ledger_entries', 1);
        $this->assertDatabaseHas('billing_ledger_entries', [
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'access_point_id' => $accessPoint->id,
            'entry_type' => BillingLedgerEntry::ENTRY_TYPE_AP_CONNECTION_FEE,
            'direction' => BillingLedgerEntry::DIRECTION_DEBIT,
            'state' => BillingLedgerEntry::STATE_POSTED,
            'currency' => 'PHP',
            'billable_key' => "ap-connection-fee:{$accessPoint->id}",
        ]);

        $accessPoint->refresh();
        $entry = BillingLedgerEntry::query()->sole();

        $this->assertSame(AccessPoint::BILLING_STATE_BILLED, $accessPoint->billing_state);
        $this->assertSame($entry->id, $accessPoint->latest_billing_entry_id);
        $this->assertSame($operator->id, $accessPoint->claimed_by_operator_id);
        $this->assertSame(AccessPoint::ADOPTION_STATE_ADOPTED, $accessPoint->adoption_state);
        $this->assertSame(AccessPoint::HEALTH_STATE_CONNECTED, $accessPoint->health_state);
        $this->assertNotNull($accessPoint->first_confirmed_connected_at);
        $this->assertSame('500.00', $entry->amount);
    }

    public function test_repeated_posting_attempts_do_not_create_duplicate_debits(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin);
        $service = app(AccessPointBillingService::class);

        $first = $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);
        $second = $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $this->assertSame(1, $first['posted']);
        $this->assertSame(1, $second['already_billed']);
        $this->assertDatabaseCount('billing_ledger_entries', 1);
        $this->assertDatabaseHas('access_points', [
            'id' => $accessPoint->id,
            'billing_state' => AccessPoint::BILLING_STATE_BILLED,
        ]);
    }

    public function test_reconnect_flap_does_not_create_a_second_debit(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin);
        $service = app(AccessPointBillingService::class);

        $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);
        $firstConfirmed = $accessPoint->fresh()->first_confirmed_connected_at;

        $accessPoint->forceFill([
            'health_state' => AccessPoint::HEALTH_STATE_DISCONNECTED,
            'health_checked_at' => now()->subMinute(),
            'status_source_event_at' => now()->subMinute(),
            'last_disconnected_at' => now()->subMinute(),
        ])->save();
        $accessPoint->forceFill([
            'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
            'health_checked_at' => now(),
            'status_source_event_at' => now(),
            'last_connected_at' => now(),
        ])->save();

        $this->noteFreshHealthAutomation();
        $result = $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $accessPoint->refresh();

        $this->assertSame(1, $result['already_billed']);
        $this->assertDatabaseCount('billing_ledger_entries', 1);
        $this->assertTrue($accessPoint->first_confirmed_connected_at->equalTo($firstConfirmed));
    }

    public function test_degraded_automation_blocks_auto_posting_safely(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin);

        $result = app(AccessPointBillingService::class)->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $this->assertSame(1, $result['blocked']);
        $this->assertDatabaseCount('billing_ledger_entries', 0);
        $this->assertDatabaseHas('access_points', [
            'id' => $accessPoint->id,
            'billing_state' => AccessPoint::BILLING_STATE_BLOCKED,
            'billing_block_reason' => AccessPointBillingService::BILLING_BLOCK_HEALTH_AUTOMATION_DEGRADED,
        ]);
    }

    public function test_stale_health_signal_blocks_billing_even_when_runtime_heartbeats_are_fresh(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin, [
            'health_state' => AccessPoint::HEALTH_STATE_STALE_UNKNOWN,
            'health_checked_at' => now()->subMinutes(20),
            'status_source_event_at' => now()->subMinutes(20),
            'last_seen_at' => now()->subMinutes(20),
            'last_connected_at' => now()->subMinutes(20),
            'health_metadata' => [
                'confidence' => 'stale',
                'stale_reason' => 'health_signal_expired',
            ],
        ]);

        $result = app(AccessPointBillingService::class)->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $this->assertSame(1, $result['blocked']);
        $this->assertDatabaseCount('billing_ledger_entries', 0);
        $this->assertDatabaseHas('access_points', [
            'id' => $accessPoint->id,
            'billing_state' => AccessPoint::BILLING_STATE_BLOCKED,
            'billing_block_reason' => AccessPointBillingService::BILLING_BLOCK_STALE_HEALTH,
        ]);
    }

    public function test_access_point_without_valid_current_ownership_does_not_bill(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin, [
            'approved_claim_id' => null,
            'ownership_corrected_at' => null,
        ]);

        $result = app(AccessPointBillingService::class)->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $this->assertSame(1, $result['blocked']);
        $this->assertDatabaseCount('billing_ledger_entries', 0);
        $this->assertDatabaseHas('access_points', [
            'id' => $accessPoint->id,
            'billing_state' => AccessPoint::BILLING_STATE_BLOCKED,
            'billing_block_reason' => AccessPointBillingService::BILLING_BLOCK_INVALID_OWNERSHIP,
        ]);
    }

    public function test_confirmed_connection_that_predates_trusted_ownership_is_blocked(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin, [
            'ownership_verified_at' => now()->subMinutes(3),
            'first_confirmed_connected_at' => now()->subMinutes(9),
        ]);

        $result = app(AccessPointBillingService::class)->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $this->assertSame(1, $result['blocked']);
        $this->assertDatabaseCount('billing_ledger_entries', 0);
        $this->assertDatabaseHas('access_points', [
            'id' => $accessPoint->id,
            'billing_state' => AccessPoint::BILLING_STATE_BLOCKED,
            'billing_block_reason' => AccessPointBillingService::BILLING_BLOCK_CONFIRMED_CONNECTION_PREDATES_OWNERSHIP,
        ]);
    }

    public function test_predates_trusted_ownership_block_can_be_resolved_without_overwriting_first_confirmed_connected_at(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin, [
            'ownership_verified_at' => now()->subMinutes(3),
            'first_confirmed_connected_at' => now()->subMinutes(9),
        ]);
        $service = app(AccessPointBillingService::class);

        $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);
        $firstConfirmed = $accessPoint->fresh()->first_confirmed_connected_at;

        $service->resolveBillingIncident(
            $accessPoint,
            $admin,
            AccessPointBillingService::RESOLUTION_ACTION_CONFIRM_ELIGIBILITY,
            'Fresh ownership review completed',
            'Ownership is now verified and health is fresh.',
        );

        $resolved = $accessPoint->fresh();

        $this->assertTrue($resolved->first_confirmed_connected_at->equalTo($firstConfirmed));
        $this->assertNotNull($resolved->billing_eligibility_confirmed_at);
        $this->assertSame($admin->id, $resolved->billing_eligibility_confirmed_by_user_id);
        $this->assertSame(AccessPoint::BILLING_STATE_UNBILLED, $resolved->billing_state);
        $this->assertNull($resolved->billing_incident_state);

        $result = $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $this->assertSame(1, $result['posted']);
        $this->assertDatabaseCount('billing_ledger_entries', 1);
        $this->assertDatabaseHas('access_points', [
            'id' => $accessPoint->id,
            'billing_state' => AccessPoint::BILLING_STATE_BILLED,
        ]);
    }

    public function test_blocked_access_point_cannot_be_resolved_when_health_freshness_is_insufficient(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin, [
            'ownership_verified_at' => now()->subMinutes(3),
            'first_confirmed_connected_at' => now()->subMinutes(9),
        ]);
        $service = app(AccessPointBillingService::class);

        $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $accessPoint->forceFill([
            'health_state' => AccessPoint::HEALTH_STATE_STALE_UNKNOWN,
            'health_checked_at' => now()->subMinutes(20),
            'status_source_event_at' => now()->subMinutes(20),
            'health_metadata' => [
                'confidence' => 'stale',
            ],
        ])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Billing resolution is blocked because the AP health signal is stale.');

        $service->resolveBillingIncident(
            $accessPoint,
            $admin,
            AccessPointBillingService::RESOLUTION_ACTION_CONFIRM_ELIGIBILITY,
            'Unsafe resolution attempt',
            null,
        );
    }

    public function test_ownership_correction_after_billing_becomes_explicit_and_does_not_silently_repost(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin);
        $billingService = app(AccessPointBillingService::class);

        $billingService->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        [, $otherOperator, $otherSite] = $this->createApprovedOperator('other-operator@example.com', 'Other Operator');

        app(AccessPointClaimService::class)->correctOwnership($accessPoint, $admin, [
            'operator_id' => $otherOperator->id,
            'site_id' => $otherSite->id,
            'correction_reason' => 'Wrong operator assignment',
            'notes' => 'Billing needs manual resolution.',
        ]);

        $this->noteFreshHealthAutomation();
        $result = $billingService->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $accessPoint->refresh();

        $this->assertSame(1, $result['blocked']);
        $this->assertDatabaseCount('billing_ledger_entries', 1);
        $this->assertSame(AccessPoint::BILLING_STATE_BLOCKED, $accessPoint->billing_state);
        $this->assertSame(
            AccessPointBillingService::BILLING_BLOCK_OWNERSHIP_CORRECTED_AFTER_BILLING,
            $accessPoint->billing_block_reason
        );
        $this->assertSame(
            AccessPoint::BILLING_INCIDENT_CORRECTED_AFTER_BILLING,
            $accessPoint->billing_incident_state
        );
    }

    public function test_reversal_and_repost_resolution_preserves_immutable_ledger_history(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin);
        $service = app(AccessPointBillingService::class);

        $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        [, $otherOperator, $otherSite] = $this->createApprovedOperator('rebill@example.com', 'Rebill Operator');

        app(AccessPointClaimService::class)->correctOwnership($accessPoint, $admin, [
            'operator_id' => $otherOperator->id,
            'site_id' => $otherSite->id,
            'correction_reason' => 'Wrong operator assignment',
            'notes' => 'Needs rebill to corrected owner.',
        ]);

        $this->noteFreshHealthAutomation();
        $service->resolveBillingIncident(
            $accessPoint,
            $admin,
            AccessPointBillingService::RESOLUTION_ACTION_AUTHORIZE_REPOST,
            'Reverse old charge and authorize rebill',
            'Corrected owner is now verified.',
        );

        $resolved = $accessPoint->fresh();

        $this->assertSame(1, $resolved->billing_charge_generation);
        $this->assertSame(AccessPoint::BILLING_STATE_UNBILLED, $resolved->billing_state);
        $this->assertNull($resolved->billing_incident_state);

        $this->noteFreshHealthAutomation();
        $result = $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $this->assertSame(1, $result['posted']);

        $entries = BillingLedgerEntry::query()
            ->where('access_point_id', $accessPoint->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $entries);
        $this->assertSame(BillingLedgerEntry::DIRECTION_DEBIT, $entries[0]->direction);
        $this->assertSame(BillingLedgerEntry::STATE_REVERSED, $entries[0]->fresh()->state);
        $this->assertSame(BillingLedgerEntry::DIRECTION_CREDIT, $entries[1]->direction);
        $this->assertSame($entries[0]->id, $entries[1]->reversal_of_id);
        $this->assertSame(BillingLedgerEntry::DIRECTION_DEBIT, $entries[2]->direction);
        $this->assertSame("ap-connection-fee:{$accessPoint->id}:rebill:1", $entries[2]->billable_key);
        $this->assertSame($otherOperator->id, $entries[2]->operator_id);
        $this->assertSame($otherSite->id, $entries[2]->site_id);

        $accessPoint->refresh();
        $this->assertSame($otherOperator->id, $accessPoint->claimed_by_operator_id);
        $this->assertSame($otherSite->id, $accessPoint->site_id);
        $this->assertSame(AccessPoint::HEALTH_STATE_CONNECTED, $accessPoint->health_state);
        $this->assertSame(AccessPoint::ADOPTION_STATE_ADOPTED, $accessPoint->adoption_state);
        $this->assertSame(AccessPoint::BILLING_STATE_BILLED, $accessPoint->billing_state);
    }

    public function test_duplicate_resolution_attempts_and_repeated_posting_do_not_create_duplicate_rebills(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin);
        $service = app(AccessPointBillingService::class);

        $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        [, $otherOperator, $otherSite] = $this->createApprovedOperator('dup-rebill@example.com', 'Dup Rebill');

        app(AccessPointClaimService::class)->correctOwnership($accessPoint, $admin, [
            'operator_id' => $otherOperator->id,
            'site_id' => $otherSite->id,
            'correction_reason' => 'Wrong operator assignment',
            'notes' => null,
        ]);

        $this->noteFreshHealthAutomation();
        $service->resolveBillingIncident(
            $accessPoint,
            $admin,
            AccessPointBillingService::RESOLUTION_ACTION_AUTHORIZE_REPOST,
            'Authorize one rebill only',
            null,
        );

        try {
            $service->resolveBillingIncident(
                $accessPoint,
                $admin,
                AccessPointBillingService::RESOLUTION_ACTION_AUTHORIZE_REPOST,
                'Duplicate resolution',
                null,
            );
            $this->fail('Expected duplicate billing incident resolution to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'This AP is not waiting for corrected-ownership billing resolution.',
                $exception->getMessage()
            );
        }

        $this->noteFreshHealthAutomation();
        $first = $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);
        $second = $service->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $this->assertSame(1, $first['posted']);
        $this->assertSame(0, $second['posted']);
        $this->assertCount(
            3,
            BillingLedgerEntry::query()->where('access_point_id', $accessPoint->id)->get()
        );
    }

    public function test_reversal_creates_compensating_credit_and_duplicate_reversal_is_blocked(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin);
        $billingService = app(AccessPointBillingService::class);

        $billingService->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);
        $credit = $billingService->reverseConnectionFee($accessPoint, $admin, 'Wrong operator assignment', 'Manual correction required.');

        $debit = BillingLedgerEntry::query()
            ->where('access_point_id', $accessPoint->id)
            ->where('direction', BillingLedgerEntry::DIRECTION_DEBIT)
            ->sole();

        $accessPoint->refresh();

        $this->assertSame(BillingLedgerEntry::DIRECTION_CREDIT, $credit->direction);
        $this->assertSame($debit->id, $credit->reversal_of_id);
        $this->assertSame(BillingLedgerEntry::STATE_REVERSED, $debit->fresh()->state);
        $this->assertNotNull($debit->fresh()->voided_at);
        $this->assertSame(AccessPoint::BILLING_STATE_REVERSED, $accessPoint->billing_state);

        $this->expectException(RuntimeException::class);
        $billingService->reverseConnectionFee($accessPoint, $admin, 'Duplicate reversal', null);
    }

    public function test_admin_and_operator_pages_expose_billing_status_fields(): void
    {
        $this->withoutVite();
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [$operatorUser, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin);

        app(AccessPointBillingService::class)->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $this->actingAs($admin)
            ->get('/admin/access-points')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AccessPoints')
                ->has('billingRuntime')
                ->where('accessPoints.0.billing.billing_state', AccessPoint::BILLING_STATE_BILLED)
                ->where('accessPoints.0.billing.latest_entry.direction', BillingLedgerEntry::DIRECTION_DEBIT));

        $this->actingAs($operatorUser)
            ->get('/operator/devices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Devices')
                ->has('billingRuntime')
                ->where('connectedDevices.0.id', $accessPoint->id)
                ->where('connectedDevices.0.billing.billing_state', AccessPoint::BILLING_STATE_BILLED));
    }

    public function test_admin_page_exposes_billing_incident_resolution_details(): void
    {
        $this->withoutVite();
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin, [
            'ownership_verified_at' => now()->subMinutes(3),
            'first_confirmed_connected_at' => now()->subMinutes(9),
        ]);

        app(AccessPointBillingService::class)->postConnectionFees($admin, BillingLedgerEntry::SOURCE_ADMIN_RUN);

        $this->actingAs($admin)
            ->get('/admin/access-points')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AccessPoints')
                ->where('accessPoints.0.billing.billing_incident_state', AccessPoint::BILLING_INCIDENT_PREDATES_TRUSTED_OWNERSHIP)
                ->where('accessPoints.0.billing.available_resolution_actions.0', AccessPointBillingService::RESOLUTION_ACTION_CONFIRM_ELIGIBILITY));
    }

    public function test_billing_actions_routes_are_protected(): void
    {
        $this->noteFreshHealthAutomation();
        $admin = User::factory()->create(['is_admin' => true]);
        [$operatorUser, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = $this->createEligibleAccessPoint($operator, $site, $admin);

        $this->post('/admin/access-points/post-connection-fees')
            ->assertRedirect('/login');

        $this->post("/admin/access-points/{$accessPoint->id}/reverse-connection-fee", [
            'reason' => 'Not allowed',
        ])->assertRedirect('/login');

        $this->post("/admin/access-points/{$accessPoint->id}/resolve-billing-incident", [
            'action' => AccessPointBillingService::RESOLUTION_ACTION_CONFIRM_ELIGIBILITY,
            'reason' => 'Not allowed',
        ])->assertRedirect('/login');

        $this->actingAs($operatorUser)
            ->post('/admin/access-points/post-connection-fees')
            ->assertForbidden();

        $this->actingAs($operatorUser)
            ->post("/admin/access-points/{$accessPoint->id}/reverse-connection-fee", [
                'reason' => 'Not allowed',
            ])->assertForbidden();

        $this->actingAs($operatorUser)
            ->post("/admin/access-points/{$accessPoint->id}/resolve-billing-incident", [
                'action' => AccessPointBillingService::RESOLUTION_ACTION_CONFIRM_ELIGIBILITY,
                'reason' => 'Not allowed',
            ])->assertForbidden();
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
            'omada_site_id' => 'site-'.self::$deviceSequence,
        ]);

        return [$user, $operator, $site];
    }

    private function createEligibleAccessPoint(Operator $operator, Site $site, User $admin, array $overrides = []): AccessPoint
    {
        $sequence = self::$deviceSequence++;
        $serial = sprintf('SN-%06d', $sequence);
        $mac = sprintf('AA:BB:CC:%02X:%02X:%02X', ($sequence >> 16) & 0xFF, ($sequence >> 8) & 0xFF, $sequence & 0xFF);

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
            'ip_address' => '192.168.1.'.($sequence % 200 + 10),
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'adoption_state' => AccessPoint::ADOPTION_STATE_ADOPTED,
            'claimed_at' => now()->subMinutes(20),
            'ownership_verified_at' => now()->subMinutes(10),
            'ownership_verified_by_user_id' => $admin->id,
            'billing_state' => AccessPoint::BILLING_STATE_UNBILLED,
            'last_synced_at' => now()->subMinute(),
            'is_online' => true,
            'health_state' => AccessPoint::HEALTH_STATE_CONNECTED,
            'health_checked_at' => now()->subMinute(),
            'status_source' => AccessPoint::STATUS_SOURCE_RECONCILE,
            'status_source_event_at' => now()->subMinute(),
            'last_seen_at' => now()->subMinute(),
            'first_connected_at' => now()->subMinutes(6),
            'last_connected_at' => now()->subMinute(),
            'first_confirmed_connected_at' => now()->subMinutes(5),
            'health_metadata' => [
                'confidence' => 'confirmed',
                'controller_observations' => [
                    'connected_streak' => 2,
                    'last_state' => AccessPoint::HEALTH_STATE_CONNECTED,
                    'source' => AccessPoint::STATUS_SOURCE_RECONCILE,
                ],
            ],
        ], $overrides));
    }

    private function noteFreshHealthAutomation(): void
    {
        $healthService = app(AccessPointHealthService::class);
        $healthService->noteSyncHeartbeat(now());
        $healthService->noteReconcileHeartbeat(now());
    }
}
