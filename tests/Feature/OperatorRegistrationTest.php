<?php

namespace Tests\Feature;

use App\Models\Operator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperatorRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_operator_registration_creates_pending_operator_and_user(): void
    {
        $response = $this->post('/operator/register', [
            'business_name' => 'North WiFi',
            'contact_name' => 'Jane Operator',
            'email' => 'operator@example.com',
            'phone_number' => '09171234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'site_name_request' => 'North Site',
            'payout_method' => 'bank',
            'payout_account_name' => 'North WiFi',
            'payout_account_reference' => '1234567890',
            'payout_notes' => 'Manual payout first.',
        ]);

        $response->assertRedirect('/admin/login');
        $response->assertSessionHas('status', 'Operator registration submitted. Wait for admin approval before signing in.');

        $user = User::query()->where('email', 'operator@example.com')->firstOrFail();
        $operator = Operator::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertFalse($user->is_admin);
        $this->assertSame(Operator::STATUS_PENDING, $operator->status);
        $this->assertSame('North WiFi', $operator->business_name);
        $this->assertSame('North Site', $operator->requested_site_name);
        $this->assertSame('bank', $operator->payout_preferences['method']);
    }
}
