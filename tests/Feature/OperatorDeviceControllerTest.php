<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Operator;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperatorDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_devices_page_includes_unclaimed_operator_access_points_in_pending_inventory(): void
    {
        $this->withoutVite();

        $user = User::factory()->create([
            'is_admin' => false,
            'email' => 'operator@example.com',
        ]);

        $operator = Operator::query()->create([
            'user_id' => $user->id,
            'business_name' => 'North WiFi',
            'contact_name' => 'North Operator',
            'phone_number' => '09171234567',
            'status' => Operator::STATUS_APPROVED,
        ]);

        $ownedSite = Site::query()->create([
            'operator_id' => $operator->id,
            'name' => 'North Site',
            'slug' => 'north-site',
        ]);
        $otherSite = Site::query()->create([
            'name' => 'South Site',
            'slug' => 'south-site',
        ]);

        AccessPoint::query()->create([
            'site_id' => $ownedSite->id,
            'name' => 'North AP',
            'mac_address' => '11:22:33:44:55:66',
            'claim_status' => AccessPoint::CLAIM_STATUS_UNCLAIMED,
            'is_online' => true,
            'last_synced_at' => now(),
        ]);
        AccessPoint::query()->create([
            'site_id' => $otherSite->id,
            'name' => 'South AP',
            'mac_address' => '22:33:44:55:66:77',
            'claim_status' => AccessPoint::CLAIM_STATUS_UNCLAIMED,
            'is_online' => true,
            'last_synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/operator/devices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Operator/Devices')
                ->has('pendingDevices', 1)
                ->where('pendingDevices.0.name', 'North AP')
                ->where('pendingDevices.0.claim_status', AccessPoint::CLAIM_STATUS_UNCLAIMED)
                ->has('connectedDevices', 0)
                ->has('failedDevices', 0));
    }
}
