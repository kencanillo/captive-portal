<?php

namespace Tests\Feature\Auth;

use App\Models\Operator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_registration_screen_can_be_rendered(): void
    {
        $this->get('/operator/register')
            ->assertOk();
    }

    public function test_new_operator_users_can_register(): void
    {
        $response = $this->post('/operator/register', [
            'business_name' => 'North WiFi',
            'contact_name' => 'Test Operator',
            'email' => 'operator@example.com',
            'phone_number' => '09171234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect('/admin/login');

        $user = User::query()->where('email', 'operator@example.com')->firstOrFail();
        $operator = Operator::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('Test Operator', $user->name);
        $this->assertSame(Operator::STATUS_PENDING, $operator->status);
    }
}
