<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\AccessPointClaim;
use App\Models\AccessPointOwnershipCorrection;
use App\Models\ControllerSetting;
use App\Models\Operator;
use App\Models\Site;
use App\Models\User;
use App\Services\OmadaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AccessPointClaimWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_submit_claim_for_intended_device_and_site(): void
    {
        [$user, $operator, $site] = $this->createApprovedOperator();

        $this->actingAs($user)
            ->post('/operator/access-point-claims', [
                'site_id' => $site->id,
                'requested_serial_number' => 'sn-12345',
                'requested_mac_address' => 'aa-bb-cc-dd-ee-ff',
                'requested_ap_name' => 'Front Gate AP',
            ])
            ->assertRedirect('/operator/devices');

        $this->assertDatabaseHas('access_point_claims', [
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number_normalized' => 'SN-12345',
            'requested_mac_address_normalized' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_duplicate_open_claims_for_same_operator_are_reused(): void
    {
        [$user, $operator, $site] = $this->createApprovedOperator();

        $firstResponse = $this->actingAs($user)
            ->post('/operator/access-point-claims', [
                'site_id' => $site->id,
                'requested_serial_number' => 'SN-12345',
            ]);

        $firstResponse->assertRedirect('/operator/devices');

        $secondResponse = $this->actingAs($user)
            ->post('/operator/access-point-claims', [
                'site_id' => $site->id,
                'requested_serial_number' => 'SN-12345',
            ]);

        $secondResponse->assertRedirect('/operator/devices');

        $this->assertSame(1, AccessPointClaim::query()->count());
    }

    public function test_conflicting_claim_on_same_device_fingerprint_is_blocked(): void
    {
        [$firstUser, , $firstSite] = $this->createApprovedOperator('alpha@example.com', 'Alpha Operator');
        [$secondUser, , $secondSite] = $this->createApprovedOperator('beta@example.com', 'Beta Operator');

        $this->actingAs($firstUser)
            ->post('/operator/access-point-claims', [
                'site_id' => $firstSite->id,
                'requested_mac_address' => 'AA:BB:CC:DD:EE:FF',
            ]);

        $this->actingAs($secondUser)
            ->from('/operator/devices')
            ->post('/operator/access-point-claims', [
                'site_id' => $secondSite->id,
                'requested_mac_address' => 'AA:BB:CC:DD:EE:FF',
            ])
            ->assertRedirect('/operator/devices')
            ->assertSessionHasErrors('requested_mac_address');

        $this->assertSame(1, AccessPointClaim::query()->count());
    }

    public function test_admin_approval_marks_claim_approved_without_assigning_ownership(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$operatorUser, $operator, $site] = $this->createApprovedOperator();
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
            'claimed_at' => now(),
        ]);

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'last_synced_at' => now(),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('syncAccessPoints')->once()->andReturn([
            'created' => 0,
            'updated' => 1,
            'claimed' => 0,
            'pending' => 1,
            'total' => 1,
        ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($admin)
            ->post("/admin/access-point-claims/{$claim->id}/approve", [
                'review_notes' => 'Serial matched pending controller inventory.',
            ])
            ->assertRedirect('/admin/access-point-claims');

        $claim->refresh();
        $accessPoint->refresh();

        $this->assertSame(AccessPointClaim::STATUS_APPROVED, $claim->claim_status);
        $this->assertSame($accessPoint->id, $claim->matched_access_point_id);
        $this->assertNull($accessPoint->claimed_by_operator_id);
        $this->assertNull($accessPoint->approved_claim_id);
    }

    public function test_adoption_from_approved_claim_succeeds_and_records_ownership_metadata(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$operatorUser, $operator, $site] = $this->createApprovedOperator();
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'last_synced_at' => now(),
        ]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_APPROVED,
            'claim_match_status' => AccessPointClaim::MATCH_STATUS_RESERVED,
            'claimed_at' => now()->subMinute(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $admin->id,
            'matched_access_point_id' => $accessPoint->id,
            'matched_omada_device_id' => 'device-001',
            'match_snapshot' => [
                'matched_access_point_id' => $accessPoint->id,
                'matched_omada_device_id' => 'device-001',
                'site_id' => $site->id,
                'serial_number_normalized' => 'SN-12345',
                'mac_address_normalized' => 'AA:BB:CC:DD:EE:FF',
            ],
            'matched_at' => now()->subMinute(),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('syncAccessPoints')->once()->andReturn([
            'created' => 0,
            'updated' => 1,
            'claimed' => 0,
            'pending' => 1,
            'total' => 1,
        ]);
        $omadaService->shouldReceive('adoptDevice')
            ->once()
            ->withArgs(fn ($settings, $mac) => $mac === 'AA:BB:CC:DD:EE:FF')
            ->andReturn(['errorCode' => 0, 'msg' => 'Success.']);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($operatorUser)
            ->post("/operator/access-point-claims/{$claim->id}/adopt")
            ->assertRedirect('/operator/devices');

        $claim->refresh();
        $accessPoint->refresh();

        $this->assertSame(AccessPointClaim::STATUS_ADOPTED, $claim->claim_status);
        $this->assertSame($operator->id, $accessPoint->claimed_by_operator_id);
        $this->assertSame($claim->id, $accessPoint->approved_claim_id);
        $this->assertSame(AccessPoint::ADOPTION_STATE_ADOPTED, $accessPoint->adoption_state);
        $this->assertSame($admin->id, $accessPoint->ownership_verified_by_user_id);
    }

    public function test_operator_cannot_adopt_without_approved_claim(): void
    {
        [$operatorUser, $operator, $site] = $this->createApprovedOperator();
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
            'claimed_at' => now(),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('syncAccessPoints')->once()->andReturn([
            'created' => 0,
            'updated' => 0,
            'claimed' => 0,
            'pending' => 0,
            'total' => 0,
        ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($operatorUser)
            ->post("/operator/access-point-claims/{$claim->id}/adopt")
            ->assertRedirect('/operator/devices')
            ->assertSessionHas('error');

        $claim->refresh();
        $this->assertSame(AccessPointClaim::STATUS_PENDING_REVIEW, $claim->claim_status);
    }

    public function test_operator_cannot_adopt_another_operators_claimed_device(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$firstUser, $firstOperator, $site] = $this->createApprovedOperator();
        [$secondUser] = $this->createApprovedOperator('other@example.com', 'Other Operator');
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'last_synced_at' => now(),
        ]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $firstOperator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_APPROVED,
            'claimed_at' => now()->subMinute(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $admin->id,
            'matched_access_point_id' => $accessPoint->id,
            'matched_omada_device_id' => 'device-001',
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('syncAccessPoints')->once()->andReturn([
            'created' => 0,
            'updated' => 1,
            'claimed' => 0,
            'pending' => 1,
            'total' => 1,
        ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($secondUser)
            ->post("/operator/access-point-claims/{$claim->id}/adopt")
            ->assertRedirect('/operator/devices')
            ->assertSessionHas('error');

        $claim->refresh();
        $this->assertSame(AccessPointClaim::STATUS_APPROVED, $claim->claim_status);
    }

    public function test_stale_claim_fails_if_pending_device_no_longer_matches(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$operatorUser, $operator, $site] = $this->createApprovedOperator();
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'serial_number' => 'SN-99999',
            'omada_device_id' => 'device-001',
            'name' => 'Wrong AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'last_synced_at' => now(),
        ]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_APPROVED,
            'claimed_at' => now()->subMinute(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $admin->id,
            'matched_access_point_id' => $accessPoint->id,
            'matched_omada_device_id' => 'device-001',
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('syncAccessPoints')->once()->andReturn([
            'created' => 0,
            'updated' => 1,
            'claimed' => 0,
            'pending' => 1,
            'total' => 1,
        ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($operatorUser)
            ->post("/operator/access-point-claims/{$claim->id}/adopt")
            ->assertRedirect('/operator/devices')
            ->assertSessionHas('error');

        $claim->refresh();
        $accessPoint->refresh();

        $this->assertSame(AccessPointClaim::STATUS_PENDING_REVIEW, $claim->claim_status);
        $this->assertTrue($claim->requires_re_review);
        $this->assertNull($accessPoint->claimed_by_operator_id);
    }

    public function test_denied_claim_leaves_access_point_unowned(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'last_synced_at' => now(),
        ]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
            'claimed_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/access-point-claims/{$claim->id}/deny", [
                'denial_reason' => 'Fingerprint does not match the live device.',
                'review_notes' => 'Operator needs to resubmit with the correct serial.',
            ])
            ->assertRedirect('/admin/access-point-claims');

        $claim->refresh();
        $accessPoint->refresh();

        $this->assertSame(AccessPointClaim::STATUS_DENIED, $claim->claim_status);
        $this->assertNull($accessPoint->claimed_by_operator_id);
        $this->assertNull($accessPoint->approved_claim_id);
    }

    public function test_name_only_claim_is_rejected(): void
    {
        [$user, , $site] = $this->createApprovedOperator();

        $this->actingAs($user)
            ->from('/operator/devices')
            ->post('/operator/access-point-claims', [
                'site_id' => $site->id,
                'requested_ap_name' => 'Front Gate AP',
            ])
            ->assertRedirect('/operator/devices')
            ->assertSessionHasErrors([
                'requested_serial_number',
                'requested_mac_address',
            ]);

        $this->assertSame(0, AccessPointClaim::query()->count());
    }

    public function test_approval_fails_closed_when_site_fingerprint_confidence_is_insufficient(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
            'claimed_at' => now(),
        ]);

        AccessPoint::query()->create([
            'site_id' => null,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Unsafe AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'last_synced_at' => now(),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('syncAccessPoints')->once()->andReturn([
            'created' => 0,
            'updated' => 1,
            'claimed' => 0,
            'pending' => 1,
            'total' => 1,
        ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($admin)
            ->post("/admin/access-point-claims/{$claim->id}/approve")
            ->assertRedirect('/admin/access-point-claims')
            ->assertSessionHas('error');

        $claim->refresh();
        $this->assertSame(AccessPointClaim::STATUS_PENDING_REVIEW, $claim->claim_status);
    }

    public function test_duplicate_adoption_attempts_do_not_execute_twice(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$operatorUser, $operator, $site] = $this->createApprovedOperator();
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'last_synced_at' => now(),
        ]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_APPROVED,
            'claim_match_status' => AccessPointClaim::MATCH_STATUS_RESERVED,
            'claimed_at' => now()->subMinute(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $admin->id,
            'matched_access_point_id' => $accessPoint->id,
            'matched_omada_device_id' => 'device-001',
            'match_snapshot' => [
                'matched_access_point_id' => $accessPoint->id,
                'matched_omada_device_id' => 'device-001',
                'site_id' => $site->id,
                'serial_number_normalized' => 'SN-12345',
                'mac_address_normalized' => 'AA:BB:CC:DD:EE:FF',
            ],
            'matched_at' => now()->subMinute(),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('syncAccessPoints')->twice()->andReturn([
            'created' => 0,
            'updated' => 1,
            'claimed' => 0,
            'pending' => 1,
            'total' => 1,
        ]);
        $omadaService->shouldReceive('adoptDevice')->once()->andReturn(['errorCode' => 0]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($operatorUser)
            ->post("/operator/access-point-claims/{$claim->id}/adopt")
            ->assertRedirect('/operator/devices');

        $this->actingAs($operatorUser)
            ->post("/operator/access-point-claims/{$claim->id}/adopt")
            ->assertRedirect('/operator/devices');

        $claim->refresh();
        $this->assertSame(AccessPointClaim::STATUS_ADOPTED, $claim->claim_status);
    }

    public function test_approved_claim_cannot_adopt_if_match_drifts_before_adoption(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$operatorUser, $operator, $site] = $this->createApprovedOperator();
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'last_synced_at' => now(),
        ]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_APPROVED,
            'claim_match_status' => AccessPointClaim::MATCH_STATUS_RESERVED,
            'matched_access_point_id' => $accessPoint->id,
            'matched_omada_device_id' => 'device-001',
            'match_snapshot' => [
                'matched_access_point_id' => $accessPoint->id,
                'matched_omada_device_id' => 'device-001',
                'site_id' => $site->id,
                'serial_number_normalized' => 'SN-12345',
                'mac_address_normalized' => 'AA:BB:CC:DD:EE:FF',
            ],
            'matched_at' => now()->subMinute(),
            'claimed_at' => now()->subMinutes(2),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
        ]);

        $accessPoint->update([
            'omada_device_id' => 'device-002',
            'last_synced_at' => now(),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('syncAccessPoints')->once()->andReturn([
            'created' => 0,
            'updated' => 1,
            'claimed' => 0,
            'pending' => 1,
            'total' => 1,
        ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($operatorUser)
            ->post("/operator/access-point-claims/{$claim->id}/adopt")
            ->assertRedirect('/operator/devices')
            ->assertSessionHas('error');

        $claim->refresh();
        $this->assertSame(AccessPointClaim::STATUS_PENDING_REVIEW, $claim->claim_status);
        $this->assertTrue($claim->requires_re_review);
        $this->assertSame(AccessPointClaim::MATCH_STATUS_STALE_MATCH, $claim->claim_match_status);
    }

    public function test_stale_sync_blocks_approval_safely(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
            'claimed_at' => now(),
        ]);

        AccessPoint::query()->create([
            'site_id' => $site->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'last_synced_at' => now()->subMinutes(20),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('syncAccessPoints')->once()->andReturn([
            'created' => 0,
            'updated' => 1,
            'claimed' => 0,
            'pending' => 1,
            'total' => 1,
        ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($admin)
            ->post("/admin/access-point-claims/{$claim->id}/approve")
            ->assertRedirect('/admin/access-point-claims')
            ->assertSessionHas('error');

        $claim->refresh();
        $this->assertSame(AccessPointClaim::STATUS_PENDING_REVIEW, $claim->claim_status);
        $this->assertSame(AccessPointClaim::MATCH_STATUS_STALE_SYNC, $claim->claim_match_status);
    }

    public function test_split_fingerprint_claims_are_escalated_into_conflict(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $firstOperator, $site] = $this->createApprovedOperator();
        [, $secondOperator] = $this->createApprovedOperator('beta@example.com', 'Beta Operator');
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $serialClaim = AccessPointClaim::query()->create([
            'operator_id' => $firstOperator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
            'claimed_at' => now()->subMinute(),
        ]);

        $macClaim = AccessPointClaim::query()->create([
            'operator_id' => $secondOperator->id,
            'site_id' => $site->id,
            'requested_mac_address' => 'AA:BB:CC:DD:EE:FF',
            'requested_mac_address_normalized' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
            'claimed_at' => now()->subMinute(),
        ]);

        AccessPoint::query()->create([
            'site_id' => $site->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'last_synced_at' => now(),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('syncAccessPoints')->once()->andReturn([
            'created' => 0,
            'updated' => 1,
            'claimed' => 0,
            'pending' => 1,
            'total' => 1,
        ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($admin)
            ->post("/admin/access-point-claims/{$serialClaim->id}/approve")
            ->assertRedirect('/admin/access-point-claims')
            ->assertSessionHas('error');

        $serialClaim->refresh();
        $macClaim->refresh();

        $this->assertTrue($serialClaim->requires_re_review);
        $this->assertTrue($macClaim->requires_re_review);
        $this->assertSame(AccessPointClaim::MATCH_STATUS_CONFLICT, $serialClaim->claim_match_status);
        $this->assertSame(AccessPointClaim::MATCH_STATUS_CONFLICT, $macClaim->claim_match_status);
        $this->assertSame(AccessPointClaim::CONFLICT_STATE_SPLIT_FINGERPRINT, $serialClaim->conflict_state);
    }

    public function test_failed_adoption_retry_revalidates_match_instead_of_reusing_stale_snapshot(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [$operatorUser, $operator, $site] = $this->createApprovedOperator();
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_ADOPTION_FAILED,
            'last_synced_at' => now(),
        ]);

        $claim = AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_ADOPTION_FAILED,
            'claim_match_status' => AccessPointClaim::MATCH_STATUS_RESERVED,
            'matched_access_point_id' => $accessPoint->id,
            'matched_omada_device_id' => 'device-001',
            'match_snapshot' => [
                'matched_access_point_id' => $accessPoint->id,
                'matched_omada_device_id' => 'device-001',
                'site_id' => $site->id,
                'serial_number_normalized' => 'SN-12345',
                'mac_address_normalized' => 'AA:BB:CC:DD:EE:FF',
            ],
            'matched_at' => now()->subMinute(),
            'claimed_at' => now()->subMinutes(2),
            'reviewed_at' => now()->subMinute(),
            'reviewed_by_user_id' => $admin->id,
            'adoption_result_metadata' => ['outcome' => 'controller_error', 'retryable' => true],
        ]);

        $accessPoint->update([
            'serial_number' => 'SN-99999',
            'last_synced_at' => now(),
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('syncAccessPoints')->once()->andReturn([
            'created' => 0,
            'updated' => 1,
            'claimed' => 0,
            'pending' => 1,
            'total' => 1,
        ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $this->actingAs($operatorUser)
            ->post("/operator/access-point-claims/{$claim->id}/adopt")
            ->assertRedirect('/operator/devices')
            ->assertSessionHas('error');

        $claim->refresh();
        $this->assertSame(AccessPointClaim::STATUS_PENDING_REVIEW, $claim->claim_status);
        $this->assertTrue($claim->requires_re_review);
        $this->assertSame(AccessPointClaim::MATCH_STATUS_STALE_MATCH, $claim->claim_match_status);
    }

    public function test_admin_ownership_correction_updates_current_owner_with_audit_trail(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $fromOperator, $fromSite] = $this->createApprovedOperator();
        [, $toOperator, $toSite] = $this->createApprovedOperator('beta@example.com', 'Beta Operator');

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $fromSite->id,
            'claimed_by_operator_id' => $fromOperator->id,
            'approved_claim_id' => null,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'adoption_state' => AccessPoint::ADOPTION_STATE_ADOPTED,
            'claimed_at' => now()->subDay(),
            'ownership_verified_at' => now()->subDay(),
            'last_synced_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post("/admin/access-points/{$accessPoint->id}/correct-ownership", [
                'operator_id' => $toOperator->id,
                'site_id' => $toSite->id,
                'correction_reason' => 'Original operator assignment was wrong.',
                'notes' => 'Support ticket verified the actual installer.',
            ])
            ->assertRedirect('/admin/access-points');

        $accessPoint->refresh();

        $this->assertSame($toOperator->id, $accessPoint->claimed_by_operator_id);
        $this->assertSame($toSite->id, $accessPoint->site_id);
        $this->assertSame($admin->id, $accessPoint->ownership_corrected_by_user_id);
        $this->assertSame('Original operator assignment was wrong.', $accessPoint->latest_correction_reason);
        $this->assertSame(1, AccessPointOwnershipCorrection::query()->count());
        $this->assertDatabaseHas('access_point_ownership_corrections', [
            'access_point_id' => $accessPoint->id,
            'from_operator_id' => $fromOperator->id,
            'to_operator_id' => $toOperator->id,
            'from_site_id' => $fromSite->id,
            'to_site_id' => $toSite->id,
            'corrected_by_user_id' => $admin->id,
        ]);
    }

    public function test_ownership_correction_route_requires_admin_access(): void
    {
        [$user, $operator, $site] = $this->createApprovedOperator();
        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'claimed_by_operator_id' => $operator->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'adoption_state' => AccessPoint::ADOPTION_STATE_ADOPTED,
            'last_synced_at' => now(),
        ]);

        $this->post("/admin/access-points/{$accessPoint->id}/correct-ownership", [])
            ->assertRedirect('/login');

        $this->actingAs($user)
            ->post("/admin/access-points/{$accessPoint->id}/correct-ownership", [
                'operator_id' => $operator->id,
                'site_id' => $site->id,
                'correction_reason' => 'No access.',
            ])
            ->assertForbidden();
    }

    public function test_failed_correction_does_not_partially_clear_existing_ownership(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();
        [, $otherOperator] = $this->createApprovedOperator('beta@example.com', 'Beta Operator');

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'claimed_by_operator_id' => $operator->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_CLAIMED,
            'adoption_state' => AccessPoint::ADOPTION_STATE_ADOPTED,
            'last_synced_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from('/admin/access-points')
            ->post("/admin/access-points/{$accessPoint->id}/correct-ownership", [
                'operator_id' => $otherOperator->id,
                'site_id' => $site->id,
                'correction_reason' => 'Broken reassignment.',
            ])
            ->assertRedirect('/admin/access-points')
            ->assertSessionHasErrors('site_id');

        $accessPoint->refresh();
        $this->assertSame($operator->id, $accessPoint->claimed_by_operator_id);
        $this->assertSame($site->id, $accessPoint->site_id);
        $this->assertSame(0, AccessPointOwnershipCorrection::query()->count());
    }

    public function test_admin_claim_page_exposes_re_review_and_sync_support_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();

        AccessPoint::query()->create([
            'site_id' => $site->id,
            'serial_number' => 'SN-12345',
            'omada_device_id' => 'device-001',
            'name' => 'Front Gate AP',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'claim_status' => AccessPoint::CLAIM_STATUS_PENDING,
            'adoption_state' => AccessPoint::ADOPTION_STATE_UNCLAIMED,
            'last_synced_at' => now(),
        ]);

        AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
            'claim_match_status' => AccessPointClaim::MATCH_STATUS_STALE_SYNC,
            'requires_re_review' => true,
            'sync_freshness_checked_at' => now(),
            'claimed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/access-point-claims')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AccessPointClaims/Index')
                ->where('syncHealth.is_fresh', true)
                ->where('claims.0.requires_re_review', true)
                ->where('claims.0.claim_match_status', AccessPointClaim::MATCH_STATUS_STALE_SYNC));
    }

    public function test_admin_claim_routes_require_admin_access(): void
    {
        $claim = AccessPointClaim::query()->create([
            'operator_id' => $this->createApprovedOperator()[1]->id,
            'site_id' => Site::query()->firstOrFail()->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
            'claimed_at' => now(),
        ]);

        $this->get('/admin/access-point-claims')
            ->assertRedirect('/login');

        $nonAdmin = User::factory()->create(['is_admin' => false]);
        $this->actingAs($nonAdmin)
            ->get('/admin/access-point-claims')
            ->assertForbidden();

        $this->actingAs($nonAdmin)
            ->post("/admin/access-point-claims/{$claim->id}/approve")
            ->assertForbidden();

        $this->actingAs($nonAdmin)
            ->post("/admin/access-point-claims/{$claim->id}/deny", [
                'denial_reason' => 'No access.',
            ])
            ->assertForbidden();
    }

    public function test_admin_claim_page_exposes_claim_support_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator, $site] = $this->createApprovedOperator();

        AccessPointClaim::query()->create([
            'operator_id' => $operator->id,
            'site_id' => $site->id,
            'requested_serial_number' => 'SN-12345',
            'requested_serial_number_normalized' => 'SN-12345',
            'claim_status' => AccessPointClaim::STATUS_PENDING_REVIEW,
            'claimed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/access-point-claims')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AccessPointClaims/Index')
                ->has('claims', 1)
                ->where('claims.0.claim_status', AccessPointClaim::STATUS_PENDING_REVIEW)
                ->where('claims.0.requested_serial_number', 'SN-12345'));
    }

    private function createApprovedOperator(
        string $email = 'operator@example.com',
        string $businessName = 'Test Operator',
    ): array {
        $user = User::factory()->create([
            'email' => $email,
            'is_admin' => false,
        ]);

        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => $businessName,
            'contact_name' => 'Operator Contact',
            'phone_number' => '09170000000',
            'status' => Operator::STATUS_APPROVED,
        ]);

        $site = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => "{$businessName} Site",
            'slug' => str($businessName)->slug()->toString(),
        ]);

        return [$user, $operator, $site];
    }
}
