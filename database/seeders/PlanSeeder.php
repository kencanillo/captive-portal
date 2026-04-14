<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Quick Surf 1 Hour',
                'description' => 'Fast starter promo for short browsing sessions.',
                'price' => 20,
                'duration_minutes' => 60,
                'speed_limit' => '5Mbps',
                'supports_pause' => true,
                'enforce_no_tethering' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Value Surf 12 Hours',
                'description' => 'Longer promo for regular customers who need the option to pause.',
                'price' => 75,
                'duration_minutes' => 720,
                'speed_limit' => '8Mbps',
                'supports_pause' => true,
                'enforce_no_tethering' => true,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Full Day 24 Hours',
                'description' => 'All-day promo with anti-tethering enabled by default.',
                'price' => 125,
                'duration_minutes' => 1440,
                'speed_limit' => '10Mbps',
                'supports_pause' => true,
                'enforce_no_tethering' => true,
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['name' => $plan['name']], $plan);
        }
    }
}
