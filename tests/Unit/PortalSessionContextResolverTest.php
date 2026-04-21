<?php

namespace Tests\Unit;

use App\Models\AccessPoint;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Site;
use App\Models\WifiSession;
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
            'mac_address' => 'AC:A7:F1:32:DB:3E',
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
            'mac_address' => 'AC:A7:F1:32:DB:3E',
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

    public function test_it_matches_an_existing_synced_access_point_when_portal_redirect_uses_hyphenated_mac(): void
    {
        $site = Site::query()->create([
            'name' => 'Test_Operator_Site',
            'slug' => 'test-operator-site',
            'omada_site_id' => '69e694f3482fc124ba7e0b8c',
        ]);
        $accessPoint = AccessPoint::query()->create([
            'site_id' => $site->id,
            'name' => 'Synced AP',
            'mac_address' => 'AC:A7:F1:32:DB:4B',
            'is_online' => true,
        ]);

        $context = app(PortalSessionContextResolver::class)->resolve([
            'site_identifier' => '69e694f3482fc124ba7e0b8c',
            'site_name' => 'Test_Operator_Site',
            'ap_mac' => 'ac-a7-f1-32-db-4b',
            'ap_name' => 'Synced AP',
        ]);

        $this->assertSame($site->id, $context['site_id']);
        $this->assertSame($accessPoint->id, $context['access_point_id']);
        $this->assertSame('AC:A7:F1:32:DB:4B', $context['ap_mac']);
        $this->assertDatabaseCount('access_points', 1);
    }

    public function test_it_upgrades_a_placeholder_site_named_by_omada_id_into_the_real_site(): void
    {
        $placeholderSite = Site::query()->create([
            'name' => '69e694f3482fc124ba7e0b8c',
            'slug' => '69e694f3482fc124ba7e0b8c',
        ]);

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $placeholderSite->id,
            'name' => 'Test Operator AP',
            'mac_address' => 'AC:A7:F1:32:DB:4B',
            'is_online' => true,
        ]);

        $client = Client::query()->create([
            'name' => 'Kenn',
            'phone_number' => '09059351839',
            'pin' => bcrypt('1234'),
            'mac_address' => 'd2:b1:66:a2:9f:46',
            'last_connected_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'name' => 'Quick Surf 1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $session = WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'site_id' => $placeholderSite->id,
            'access_point_id' => $accessPoint->id,
            'mac_address' => $client->mac_address,
            'ap_mac' => $accessPoint->mac_address,
            'ap_name' => $accessPoint->name,
            'ssid_name' => 'Test_Coinless_Wifi_Vendo',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now(),
            'end_time' => now()->addHour(),
        ]);

        $context = app(PortalSessionContextResolver::class)->resolve([
            'site_identifier' => '69e694f3482fc124ba7e0b8c',
            'site_name' => 'Test_Operator_Site',
            'ap_mac' => $accessPoint->mac_address,
            'ap_name' => 'Test Operator AP',
        ]);

        $resolvedSite = Site::query()->findOrFail($context['site_id']);

        $this->assertSame($placeholderSite->id, $resolvedSite->id);
        $this->assertSame('Test_Operator_Site', $resolvedSite->name);
        $this->assertSame('69e694f3482fc124ba7e0b8c', $resolvedSite->omada_site_id);
        $this->assertDatabaseMissing('sites', [
            'id' => $placeholderSite->id,
            'name' => '69e694f3482fc124ba7e0b8c',
            'omada_site_id' => null,
        ]);
        $this->assertDatabaseHas('access_points', [
            'id' => $accessPoint->id,
            'site_id' => $resolvedSite->id,
        ]);
        $this->assertDatabaseHas('wifi_sessions', [
            'id' => $session->id,
            'site_id' => $resolvedSite->id,
        ]);
    }

    public function test_it_merges_placeholder_site_activity_into_existing_synced_site(): void
    {
        $placeholderSite = Site::query()->create([
            'name' => '69e694f3482fc124ba7e0b8c',
            'slug' => '69e694f3482fc124ba7e0b8c',
        ]);
        $syncedSite = Site::query()->create([
            'name' => 'Test_Operator_Site',
            'slug' => 'test-operator-site',
            'omada_site_id' => '69e694f3482fc124ba7e0b8c',
        ]);

        $accessPoint = AccessPoint::query()->create([
            'site_id' => $placeholderSite->id,
            'name' => 'Test Operator AP',
            'mac_address' => 'AC:A7:F1:32:DB:4B',
            'is_online' => true,
        ]);

        $client = Client::query()->create([
            'name' => 'Kenn',
            'phone_number' => '09059351839',
            'pin' => bcrypt('1234'),
            'mac_address' => 'd2:b1:66:a2:9f:46',
            'last_connected_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'name' => 'Quick Surf 1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $session = WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'site_id' => $placeholderSite->id,
            'access_point_id' => $accessPoint->id,
            'mac_address' => $client->mac_address,
            'ap_mac' => $accessPoint->mac_address,
            'ap_name' => $accessPoint->name,
            'ssid_name' => 'Test_Coinless_Wifi_Vendo',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now(),
            'end_time' => now()->addHour(),
        ]);

        $context = app(PortalSessionContextResolver::class)->resolve([
            'site_identifier' => '69e694f3482fc124ba7e0b8c',
            'site_name' => 'Test_Operator_Site',
            'ap_mac' => $accessPoint->mac_address,
            'ap_name' => 'Test Operator AP',
        ]);

        $this->assertSame($syncedSite->id, $context['site_id']);
        $this->assertDatabaseMissing('sites', [
            'id' => $placeholderSite->id,
        ]);
        $this->assertDatabaseHas('access_points', [
            'id' => $accessPoint->id,
            'site_id' => $syncedSite->id,
        ]);
        $this->assertDatabaseHas('wifi_sessions', [
            'id' => $session->id,
            'site_id' => $syncedSite->id,
        ]);
    }
}
