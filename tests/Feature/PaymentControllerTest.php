<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Site;
use App\Models\WifiSession;
use App\Services\OmadaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_payment_initializes_a_qrph_checkout_session_and_leaves_wifi_pending(): void
    {
        $site = Site::query()->create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
        ]);

        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'last_connected_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $session = WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'mac_address' => $client->mac_address,
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::STATUS_PENDING,
            'is_active' => false,
        ]);

        config()->set('services.paymongo.secret_key', 'sk_test_123');
        config()->set('services.paymongo.base_url', 'https://api.paymongo.com/v1');

        Http::fake([
            'https://api.paymongo.com/v1/checkout_sessions' => Http::response([
                'errorCode' => 0,
                'data' => [
                    'id' => 'cs_test_checkout_123',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_test_checkout_123',
                    ],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/create-payment', [
            'session_id' => $session->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.checkout_url', 'https://checkout.paymongo.com/cs_test_checkout_123')
            ->assertJsonPath('data.payment_intent_id', 'cs_test_checkout_123');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout_sessions')
            && data_get($request->data(), 'data.attributes.payment_method_types') === ['qrph']
            && data_get($request->data(), 'data.attributes.metadata.wifi_session_id') === $session->id
            && data_get($request->data(), 'data.attributes.line_items.0.name') === $plan->name);

        $session->refresh();
        $payment = Payment::query()->where('wifi_session_id', $session->id)->firstOrFail();

        $this->assertSame(WifiSession::STATUS_PENDING, $session->payment_status);
        $this->assertFalse($session->is_active);
        $this->assertNull($session->start_time);
        $this->assertNull($session->end_time);
        $this->assertSame('paymongo', $payment->provider);
        $this->assertSame(WifiSession::STATUS_PENDING, $payment->status);
        $this->assertSame('cs_test_checkout_123', $payment->reference_id);
    }

    public function test_paymongo_webhook_marks_session_paid_logs_payment_and_authorizes_client_on_omada(): void
    {
        config()->set('services.paymongo.webhook_secret', 'whsec_test_123');

        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]);

        $site = Site::query()->create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
        ]);

        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'last_connected_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'name' => '3 Minutes',
            'price' => 20,
            'duration_minutes' => 3,
        ]);

        $session = WifiSession::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'mac_address' => $client->mac_address,
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::STATUS_PENDING,
            'paymongo_payment_intent_id' => 'cs_test_checkout_123',
            'is_active' => false,
        ]);

        Payment::query()->create([
            'wifi_session_id' => $session->id,
            'provider' => 'paymongo',
            'reference_id' => 'cs_test_checkout_123',
            'status' => WifiSession::STATUS_PENDING,
            'raw_response' => [
                'seed' => true,
            ],
        ]);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->withArgs(function (ControllerSetting $settings, WifiSession $authorizedSession) use ($session): bool {
                return $settings->hotspot_operator_username === 'operator'
                    && $authorizedSession->id === $session->id
                    && $authorizedSession->radio_id === 1
                    && $authorizedSession->ssid_name === 'Guest WiFi';
            })
            ->andReturn([
                'errorCode' => 0,
                'msg' => 'Success.',
            ]);

        $this->app->instance(OmadaService::class, $omadaService);

        $payload = json_encode([
            'data' => [
                'id' => 'evt_test_123',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => 'cs_test_checkout_123',
                        'type' => 'checkout_session',
                        'attributes' => [
                            'metadata' => [
                                'wifi_session_id' => $session->id,
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_123');

        $response = $this->withHeaders([
            'Paymongo-Signature' => "t={$timestamp},v1={$signature}",
        ])->postJson('/api/paymongo/webhook', json_decode($payload, true, 512, JSON_THROW_ON_ERROR));

        $response->assertOk()
            ->assertJsonPath('success', true);

        $session->refresh();
        $payment = Payment::query()->where('wifi_session_id', $session->id)->where('reference_id', 'cs_test_checkout_123')->firstOrFail();

        $this->assertSame(WifiSession::STATUS_PAID, $session->payment_status);
        $this->assertTrue($session->is_active);
        $this->assertNotNull($session->start_time);
        $this->assertNotNull($session->end_time);
        $this->assertSame(WifiSession::STATUS_PAID, $payment->status);
        $this->assertSame('checkout_session.payment.paid', data_get($payment->raw_response, 'data.attributes.type'));
    }
}
