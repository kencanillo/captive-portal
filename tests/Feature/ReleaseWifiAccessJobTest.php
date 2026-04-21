<?php

namespace Tests\Feature;

use App\Jobs\ReleaseWifiAccessJob;
use App\Models\Payment;
use App\Models\WifiSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseWifiAccessJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_job_includes_site_operator_relationship(): void
    {
        // This test verifies the fix for the "Attempt to read property 'operator' on null" error
        
        // Create minimal test data
        $session = WifiSession::create([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'plan_id' => 1, // Add required plan_id
            'amount_paid' => 5.00, // Add required amount_paid
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_PAID,
            'is_active' => false,
        ]);

        $payment = Payment::create([
            'wifi_session_id' => $session->id,
            'status' => Payment::STATUS_PAID,
            'amount' => 5.00,
        ]);

        // Mock the WifiSessionService to capture the call
        $this->mock(WifiSessionService::class, function ($mock) {
            $mock->shouldReceive('activateSession')
                ->once()
                ->with($session)
                ->andReturn($session);
        });

        // Dispatch the job
        $job = new ReleaseWifiAccessJob($payment->id);
        
        // This should not throw "Attempt to read property 'operator' on null" error
        // because we fixed the relationship loading
        $this->expectNotToPerformAssertions();
        $job->handle();
    }
}
