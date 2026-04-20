<?php

namespace Tests\Unit;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanPricingTest extends TestCase
{
    use RefreshDatabase;
    public function test_plan_exposes_base_customer_price_and_net_amount_after_fee(): void
    {
        config()->set('portal.ewallet_fee_rate', 0.02);

        $plan = new Plan([
            'name' => 'Starter',
            'price' => 5,
            'duration_minutes' => 60,
        ]);

        $this->assertSame(0.02, $plan->processing_fee_rate);
        $this->assertSame(0.10, $plan->processing_fee_amount);
        $this->assertSame(5.00, $plan->customer_price);
        $this->assertSame(4.90, $plan->net_amount);
    }
}
