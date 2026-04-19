<?php

namespace Tests\Feature;

use App\Mail\OperatorApprovedMail;
use App\Models\Operator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminOperatorApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_approval_sends_email_once_on_transition_to_approved(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email' => 'operator@example.com', 'is_admin' => false]);
        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => 'North WiFi',
            'contact_name' => 'North Operator',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->put("/admin/operators/{$operator->id}/status", [
                'status' => Operator::STATUS_APPROVED,
                'approval_notes' => 'Approved.',
            ])
            ->assertRedirect("/admin/operators/{$operator->id}");

        Mail::assertSent(OperatorApprovedMail::class, function (OperatorApprovedMail $mail) use ($operator) {
            return $mail->operator->is($operator);
        });

        Mail::fake();

        $this->actingAs($admin)
            ->put("/admin/operators/{$operator->id}/status", [
                'status' => Operator::STATUS_APPROVED,
                'approval_notes' => 'Still approved.',
            ])
            ->assertRedirect("/admin/operators/{$operator->id}");

        Mail::assertNothingSent();
    }
}
