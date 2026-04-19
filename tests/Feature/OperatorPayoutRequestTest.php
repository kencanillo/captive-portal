<?php

namespace Tests\Feature;

use App\Models\Operator;
use App\Models\PayoutRequest;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorPayoutRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_submit_a_payout_request(): void
    {
        [$user, $operator] = $this->createApprovedOperator();
        $site = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => 'North Site',
            'slug' => 'north-site',
        ]);
        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 50,
            'duration_minutes' => 60,
            'is_active' => true,
        ]);

        WifiSession::query()->create([
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'amount_paid' => 50,
            'payment_status' => WifiSession::STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post('/operator/payouts', [
                'amount' => 25,
                'destination_type' => 'bank',
                'destination_account_name' => 'North WiFi',
                'destination_account_reference' => '1234567890',
                'destination_provider' => 'instapay',
            ])
            ->assertRedirect('/operator/payouts');

        $this->assertDatabaseHas('payout_requests', [
            'operator_id' => $operator->id,
            'amount' => 25,
            'status' => PayoutRequest::STATUS_PENDING,
            'destination_type' => 'bank',
        ]);
    }

    public function test_admin_approval_defaults_to_manual_payout_workflow(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        [, $operator] = $this->createApprovedOperator('operator2@example.com');

        $payoutRequest = PayoutRequest::query()->create([
            'operator_id' => $operator->id,
            'amount' => 30,
            'currency' => 'PHP',
            'status' => PayoutRequest::STATUS_PENDING,
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

        $this->assertSame(PayoutRequest::STATUS_APPROVED, $payoutRequest->status);
        $this->assertSame(PayoutRequest::MODE_MANUAL, $payoutRequest->processing_mode);
        $this->assertSame('manual_review_required', $payoutRequest->provider_status);
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
}
