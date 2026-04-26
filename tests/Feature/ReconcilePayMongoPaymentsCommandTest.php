<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientDevice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Site;
use App\Models\WifiSession;
use App\Services\WifiSessionReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ReconcilePayMongoPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_reconciles_paid_paymongo_payment_without_open_browser(): void
    {
        config()->set('services.paymongo.secret_key', 'sk_test_123');
        config()->set('services.paymongo.base_url', 'https://api.paymongo.com/v1');

        $payment = $this->createPendingPayment();

        Http::fake([
            'https://api.paymongo.com/v1/payment_intents/pi_test_pending_123' => Http::response([
                'data' => [
                    'id' => 'pi_test_pending_123',
                    'attributes' => [
                        'status' => 'succeeded',
                        'payments' => [[
                            'id' => 'pay_test_scheduler_123',
                            'attributes' => [
                                'external_reference_number' => 'LOCALREF123',
                                'source' => [
                                    'provider' => [
                                        'code_id' => 'code_test_pending_123',
                                    ],
                                ],
                            ],
                        ]],
                    ],
                ],
            ]),
        ]);

        $releaseService = Mockery::mock(WifiSessionReleaseService::class);
        $releaseService->shouldReceive('queueInitialRelease')
            ->once()
            ->withArgs(function (WifiSession $session, string $path, array $context) use ($payment): bool {
                return $session->id === $payment->wifi_session_id
                    && $path === 'manual_recheck'
                    && $context['payment_id'] === $payment->id;
            });
        $releaseService->shouldReceive('attemptRelease')
            ->once()
            ->withArgs(function (int $sessionId, string $path, array $context) use ($payment): bool {
                return $sessionId === $payment->wifi_session_id
                    && $path === 'scheduled_paymongo_reconcile'
                    && $context['payment_id'] === $payment->id;
            })
            ->andReturnUsing(function (int $sessionId) {
                $session = WifiSession::query()->findOrFail($sessionId);
                $session->forceFill([
                    'is_active' => true,
                    'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
                    'release_status' => WifiSession::RELEASE_STATUS_SUCCEEDED,
                ])->save();

                return $session->fresh();
            });

        $this->app->instance(WifiSessionReleaseService::class, $releaseService);

        $exitCode = Artisan::call('payments:reconcile-paymongo', ['--limit' => 10]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Checked 1 payment(s). Paid: 1. Activated: 1.', Artisan::output());

        $payment->refresh();
        $payment->wifiSession->refresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->payment_status);
        $this->assertSame(WifiSession::PAYMENT_STATUS_PAID, $payment->wifiSession->payment_status);
        $this->assertTrue($payment->wifiSession->is_active);
        $this->assertSame(WifiSession::SESSION_STATUS_ACTIVE, $payment->wifiSession->session_status);
    }

    private function createPendingPayment(): Payment
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

        $clientDevice = ClientDevice::query()->create([
            'client_id' => $client->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'bound',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $session = WifiSession::query()->create([
            'client_id' => $client->id,
            'client_device_id' => $clientDevice->id,
            'plan_id' => $plan->id,
            'site_id' => $site->id,
            'mac_address' => $client->mac_address,
            'ap_mac' => '11:22:33:44:55:66',
            'ap_name' => 'North Pole AP',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 1,
            'client_ip' => '192.168.20.10',
            'amount_paid' => $plan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
        ]);

        return Payment::query()->create([
            'wifi_session_id' => $session->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'LOCALREF123',
            'status' => Payment::STATUS_AWAITING_PAYMENT,
            'paymongo_payment_intent_id' => 'pi_test_pending_123',
            'paymongo_payment_method_id' => 'pm_test_pending_123',
            'qr_reference' => 'code_test_pending_123',
            'qr_image_url' => 'data:image/png;base64,pendingqr',
            'qr_expires_at' => now()->addMinutes(30),
            'amount' => $plan->price,
            'currency' => 'PHP',
            'raw_response' => [
                'seed' => true,
            ],
        ]);
    }
}
