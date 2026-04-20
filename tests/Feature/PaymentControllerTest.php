<?php

namespace Tests\Feature;

use App\Jobs\ReleaseWifiAccessJob;
use App\Models\Client;
use App\Models\ControllerSetting;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Site;
use App\Models\WifiSession;
use App\Services\OmadaService;
use App\Services\WifiSessionService;
use App\Support\PortalTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_qrph_payment_attempt_and_reuse_existing_attempt_on_repeat_request(): void
    {
        $session = $this->createWifiSession();

        config()->set('services.paymongo.secret_key', 'sk_test_123');
        config()->set('services.paymongo.base_url', 'https://api.paymongo.com/v1');
        config()->set('services.paymongo.qrph_expiry_seconds', 1800);

        Http::fake([
            'https://api.paymongo.com/v1/payment_intents' => Http::response([
                'data' => [
                    'id' => 'pi_test_qrph_123',
                    'attributes' => [
                        'status' => 'awaiting_payment_method',
                    ],
                ],
            ]),
            'https://api.paymongo.com/v1/payment_methods' => Http::response([
                'data' => [
                    'id' => 'pm_test_qrph_123',
                ],
            ]),
            'https://api.paymongo.com/v1/payment_intents/pi_test_qrph_123/attach' => Http::response([
                'data' => [
                    'id' => 'pi_test_qrph_123',
                    'attributes' => [
                        'status' => 'awaiting_next_action',
                        'next_action' => [
                            'type' => 'consume_qr',
                            'code' => [
                                'id' => 'code_test_qrph_123',
                                'image_url' => 'data:image/png;base64,abc123',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $sessionToken = $this->issueSessionToken($session);

        $firstResponse = $this->postJson('/api/create-payment', [
            'session_token' => $sessionToken,
        ]);

        $firstResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_intent_id', 'pi_test_qrph_123')
            ->assertJsonPath('data.payment_status', Payment::STATUS_AWAITING_PAYMENT);

        $paymentUrl = $firstResponse->json('data.payment_url');

        $secondResponse = $this->postJson('/api/create-payment', [
            'session_token' => $sessionToken,
        ]);

        $secondResponse->assertOk()
            ->assertJsonPath('data.payment_url', $paymentUrl)
            ->assertJsonPath('data.payment_intent_id', 'pi_test_qrph_123');

        Http::assertSentCount(3);

        $session->refresh();
        $payment = Payment::query()->sole();

        $this->assertSame(WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT, $session->payment_status);
        $this->assertSame(WifiSession::SESSION_STATUS_PENDING_PAYMENT, $session->session_status);
        $this->assertSame(Payment::STATUS_AWAITING_PAYMENT, $payment->payment_status);
        $this->assertSame('pi_test_qrph_123', $payment->paymongo_payment_intent_id);
        $this->assertSame('pm_test_qrph_123', $payment->paymongo_payment_method_id);
        $this->assertSame('code_test_qrph_123', $payment->qr_reference);
        $this->assertNotNull($payment->qr_expires_at);
        $this->assertSame('25.00', (string) $payment->amount);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_create_payment_can_bypass_paymongo_for_local_multi_device_testing(): void
    {
        config()->set('portal.bypass_payment', true);

        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]);

        $session = $this->createWifiSession();
        $sessionToken = $this->issueSessionToken($session);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->andReturn([
                'errorCode' => 0,
                'msg' => 'Success.',
            ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $response = $this->postJson('/api/create-payment', [
            'session_token' => $sessionToken,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_bypassed', true)
            ->assertJsonPath('data.payment_status', Payment::STATUS_PAID);

        $payment = Payment::query()->sole();
        $payment->wifiSession->refresh();

        $this->assertSame(Payment::PROVIDER_BYPASS, $payment->provider);
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame(WifiSession::SESSION_STATUS_ACTIVE, $payment->wifiSession->session_status);
        $this->assertTrue($payment->wifiSession->is_active);
    }

    public function test_status_endpoint_returns_waiting_state_initially(): void
    {
        $payment = $this->createPendingPayment();

        $response = $this->getJson("/payments/{$this->issuePaymentToken($payment)}/status");

        $response->assertOk()
            ->assertJsonPath('data.payment_status', Payment::STATUS_AWAITING_PAYMENT)
            ->assertJsonPath('data.wifi_session_status', WifiSession::SESSION_STATUS_PENDING_PAYMENT)
            ->assertJsonPath('data.should_continue_polling', true)
            ->assertJsonPath('data.next_step', 'keep_waiting');
    }

    public function test_paymongo_payment_paid_webhook_updates_payment_and_session_and_dispatches_release_job(): void
    {
        Bus::fake();
        config()->set('services.paymongo.webhook_secret', 'whsec_test_123');

        $payment = $this->createPendingPayment([
            'paymongo_payment_intent_id' => 'pi_test_paid_123',
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt_test_paid_123',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.paid',
                    'data' => [
                        'id' => 'pay_test_paid_123',
                        'type' => 'payment',
                        'attributes' => [
                            'payment_intent_id' => 'pi_test_paid_123',
                            'external_reference_number' => 'QRREF123',
                            'paid_at' => now()->timestamp,
                            'source' => [
                                'provider' => [
                                    'code_id' => 'code_test_paid_123',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_123');

        $response = $this->call(
            'POST',
            '/api/paymongo/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Paymongo-Signature' => "t={$timestamp},te={$signature}",
            ],
            $payload
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $payment->refresh();
        $payment->wifiSession->refresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->payment_status);
        $this->assertSame('pay_test_paid_123', $payment->paymongo_payment_id);
        $this->assertSame('QRREF123', $payment->external_reference);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(WifiSession::PAYMENT_STATUS_PAID, $payment->wifiSession->payment_status);
        $this->assertSame(WifiSession::SESSION_STATUS_PAID, $payment->wifiSession->session_status);
        $this->assertSame('24.50', (string) $payment->wifiSession->amount_paid);

        Bus::assertDispatched(ReleaseWifiAccessJob::class, fn (ReleaseWifiAccessJob $job) => $job->paymentId === $payment->id);
    }

    public function test_duplicate_paid_webhook_is_idempotent(): void
    {
        Bus::fake();
        config()->set('services.paymongo.webhook_secret', 'whsec_test_123');

        $payment = $this->createPendingPayment([
            'paymongo_payment_intent_id' => 'pi_test_dup_123',
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt_test_dup_123',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.paid',
                    'data' => [
                        'id' => 'pay_test_dup_123',
                        'type' => 'payment',
                        'attributes' => [
                            'payment_intent_id' => 'pi_test_dup_123',
                            'paid_at' => now()->timestamp,
                            'source' => [
                                'provider' => [
                                    'code_id' => 'code_dup_123',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_123');

        foreach ([1, 2] as $index) {
            $response = $this->call(
                'POST',
                '/api/paymongo/webhook',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_Paymongo-Signature' => "t={$timestamp},te={$signature}",
                ],
                $payload
            );

            $response->assertOk();
        }

        $payment->refresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->payment_status);
        Bus::assertDispatchedTimes(ReleaseWifiAccessJob::class, 1);
    }

    public function test_expired_qr_becomes_expired_on_status_check(): void
    {
        $payment = $this->createPendingPayment([
            'qr_expires_at' => now()->subMinute(),
        ]);

        $response = $this->getJson("/payments/{$this->issuePaymentToken($payment)}/status");

        $response->assertOk()
            ->assertJsonPath('data.payment_status', Payment::STATUS_EXPIRED)
            ->assertJsonPath('data.should_continue_polling', false)
            ->assertJsonPath('data.next_step', 'regenerate_qr');

        $payment->refresh();
        $payment->wifiSession->refresh();

        $this->assertSame(Payment::STATUS_EXPIRED, $payment->payment_status);
        $this->assertSame(WifiSession::PAYMENT_STATUS_EXPIRED, $payment->wifiSession->payment_status);
    }

    public function test_manual_recheck_reconciles_successful_payment_intent(): void
    {
        Bus::fake();

        config()->set('services.paymongo.secret_key', 'sk_test_123');
        config()->set('services.paymongo.base_url', 'https://api.paymongo.com/v1');

        $payment = $this->createPendingPayment([
            'paymongo_payment_intent_id' => 'pi_test_recheck_123',
        ]);

        Http::fake([
            'https://api.paymongo.com/v1/payment_intents/pi_test_recheck_123' => Http::response([
                'data' => [
                    'id' => 'pi_test_recheck_123',
                    'attributes' => [
                        'status' => 'succeeded',
                        'payments' => [[
                            'id' => 'pay_test_recheck_123',
                            'type' => 'payment',
                            'attributes' => [
                                'payment_intent_id' => 'pi_test_recheck_123',
                                'external_reference_number' => 'RECHECK123',
                                'paid_at' => now()->timestamp,
                                'source' => [
                                    'provider' => [
                                        'code_id' => 'code_recheck_123',
                                    ],
                                ],
                            ],
                        ]],
                    ],
                ],
            ]),
        ]);

        $response = $this
            ->withSession(['_token' => 'test-token'])
            ->withHeader('X-CSRF-TOKEN', 'test-token')
            ->postJson("/payments/{$this->issuePaymentToken($payment)}/recheck");

        $response->assertOk()
            ->assertJsonPath('data.payment_status', Payment::STATUS_PAID)
            ->assertJsonPath('data.wifi_session_status', WifiSession::SESSION_STATUS_PAID);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_PAID, $payment->payment_status);
        $this->assertSame('24.50', (string) $payment->wifiSession->fresh()->amount_paid);
        Bus::assertDispatched(ReleaseWifiAccessJob::class, fn (ReleaseWifiAccessJob $job) => $job->paymentId === $payment->id);
    }

    public function test_refreshing_payment_page_does_not_create_duplicate_payment_attempts(): void
    {
        $payment = $this->createPendingPayment();

        $this->assertSame(1, Payment::query()->count());

        $this->get("/payments/{$this->issuePaymentToken($payment)}")
            ->assertOk();

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_create_payment_rejects_invalid_session_tokens(): void
    {
        $this->postJson('/api/create-payment', [
            'session_token' => 'bad-token',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('session_token');
    }

    public function test_release_job_marks_session_active_when_omada_authorization_succeeds(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]);

        $payment = $this->createPaidPayment();

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->andReturn([
                'errorCode' => 0,
                'msg' => 'Success.',
            ]);

        $this->app->instance(OmadaService::class, $omadaService);

        $job = new ReleaseWifiAccessJob($payment->id);
        $job->handle($this->app->make(WifiSessionService::class));

        $payment->wifiSession->refresh();

        $this->assertTrue($payment->wifiSession->is_active);
        $this->assertSame(WifiSession::SESSION_STATUS_ACTIVE, $payment->wifiSession->session_status);
        $this->assertNotNull($payment->wifiSession->start_time);
        $this->assertNotNull($payment->wifiSession->end_time);
    }

    public function test_failed_release_updates_session_status(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]);

        $payment = $this->createPaidPayment();

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->andThrow(new \RuntimeException('Omada authorization failed.'));

        $this->app->instance(OmadaService::class, $omadaService);

        $job = new ReleaseWifiAccessJob($payment->id);
        $job->handle($this->app->make(WifiSessionService::class));

        $payment->wifiSession->refresh();

        $this->assertFalse($payment->wifiSession->is_active);
        $this->assertSame(WifiSession::SESSION_STATUS_RELEASE_FAILED, $payment->wifiSession->session_status);
        $this->assertSame('Omada authorization failed.', $payment->wifiSession->release_failure_reason);
    }

    private function createPendingPayment(array $overrides = []): Payment
    {
        $session = $this->createWifiSession([
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'paymongo_payment_intent_id' => $overrides['paymongo_payment_intent_id'] ?? 'pi_test_pending_123',
        ]);

        return Payment::query()->create(array_merge([
            'wifi_session_id' => $session->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'LOCALREF123',
            'status' => Payment::STATUS_AWAITING_PAYMENT,
            'paymongo_payment_intent_id' => $session->paymongo_payment_intent_id,
            'paymongo_payment_method_id' => 'pm_test_pending_123',
            'qr_reference' => 'code_test_pending_123',
            'qr_image_url' => 'data:image/png;base64,pendingqr',
            'qr_expires_at' => now()->addMinutes(30),
            'amount' => $session->amount_paid,
            'currency' => 'PHP',
            'raw_response' => [
                'seed' => true,
            ],
        ], $overrides));
    }

    private function createPaidPayment(): Payment
    {
        $payment = $this->createPendingPayment([
            'status' => Payment::STATUS_PAID,
            'paymongo_payment_id' => 'pay_test_paid_job_123',
            'paid_at' => now(),
        ]);

        $payment->wifiSession->forceFill([
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_PAID,
        ])->save();

        return $payment->fresh(['wifiSession.plan', 'wifiSession.client', 'wifiSession.site']);
    }

    private function createWifiSession(array $overrides = []): WifiSession
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

        return WifiSession::query()->create(array_merge([
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
            'payment_status' => WifiSession::PAYMENT_STATUS_PENDING,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
        ], $overrides));
    }

    private function issueSessionToken(WifiSession $session): string
    {
        return app(PortalTokenService::class)->issueSessionToken($session);
    }

    private function issuePaymentToken(Payment $payment): string
    {
        return app(PortalTokenService::class)->issuePaymentToken($payment);
    }
}
