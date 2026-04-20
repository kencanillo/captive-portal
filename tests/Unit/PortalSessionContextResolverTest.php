<?php

namespace Tests\Unit;

use App\Models\AccessPoint;
use App\Models\Site;
use App\Services\PortalSessionContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalSessionContextResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_omada_site_identifier_to_attach_a_new_access_point_to_the_synced_site(): void
    {
        $site = Site::query()->create([
            'name' => 'Juleanne_Operator',
            'slug' => 'juleanne-operator',
            'omada_site_id' => '69e31ca32109e3181bab7109',
        ]);

        $context = app(PortalSessionContextResolver::class)->resolve([
            'site_identifier' => '69e31ca32109e3181bab7109',
            'ap_mac' => 'ac-a7-f1-32-db-3e',
            'ap_name' => 'Adopted EAP',
        ]);

        $this->assertSame($site->id, $context['site_id']);
        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseHas('access_points', [
            'mac_address' => 'ac-a7-f1-32-db-3e',
            'site_id' => $site->id,
        ]);
    }

    public function test_it_reassigns_a_mislinked_access_point_when_omada_site_identifier_matches_a_real_site(): void
    {
        $wrongSite = Site::query()->create([
            'name' => '69e0c53ac78dcd54c173e0ab',
            'slug' => '69e0c53ac78dcd54c173e0ab',
        ]);
        $correctSite = Site::query()->create([
            'name' => 'Juleanne_Operator',
            'slug' => 'juleanne-operator',
            'omada_site_id' => '69e31ca32109e3181bab7109',
        ]);
        $accessPoint = AccessPoint::query()->create([
            'site_id' => $wrongSite->id,
            'name' => 'ac-a7-f1-32-db-3e',
            'mac_address' => 'ac-a7-f1-32-db-3e',
            'is_online' => true,
        ]);

        $context = app(PortalSessionContextResolver::class)->resolve([
            'site_identifier' => '69e31ca32109e3181bab7109',
            'site_name' => 'Juleanne_Operator',
            'ap_mac' => 'ac-a7-f1-32-db-3e',
            'ap_name' => 'Juleanne Lobby AP',
        ]);

        $this->assertSame($correctSite->id, $context['site_id']);
        $this->assertDatabaseHas('access_points', [
            'id' => $accessPoint->id,
            'site_id' => $correctSite->id,
            'name' => 'Juleanne Lobby AP',
        ]);
    }
}
