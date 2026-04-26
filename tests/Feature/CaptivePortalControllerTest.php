<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CaptivePortalControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_trusted_portal_entry_page_prefetches_initial_plans_for_the_first_inertia_response(): void
    {
        Plan::query()->create([
            'name' => 'Quick Surf 30',
            'price' => 15,
            'customer_price' => 15,
            'duration_minutes' => 30,
            'sort_order' => 2,
            'is_active' => true,
        ]);

        Plan::query()->create([
            'name' => 'Quick Surf 60',
            'price' => 25,
            'customer_price' => 25,
            'duration_minutes' => 60,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->get('/?siteName=North%20Site&clientMac=aa-bb-cc-dd-ee-ff')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/PlanSelection')
                ->where('plansPrefetched', true)
                ->has('initialPlans', 2)
                ->where('initialPlans.0.name', 'Quick Surf 60')
                ->where('initialPlans.1.name', 'Quick Surf 30')
                ->where('initialPortalContext.site_name', 'North Site'));
    }

    public function test_direct_visit_without_captive_context_renders_marketing_landing_page(): void
    {
        Plan::query()->create([
            'name' => 'Quick Surf 60',
            'price' => 25,
            'customer_price' => 25,
            'duration_minutes' => 60,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $this->get('/?siteName=North%20Site')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/LandingPage')
                ->where('reconnectMessage', 'No captive portal device context detected. Connect to the Wi-Fi network and reopen the sign-in page.')
                ->where('initialPortalContext.site_name', 'North Site')
                ->has('initialPlans', 1));
    }

    public function test_portal_entry_page_uses_local_shell_markup_without_public_font_dependencies(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/LandingPage')
            );
    }
}
