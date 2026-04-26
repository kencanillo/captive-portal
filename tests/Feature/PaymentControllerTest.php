<?php

namespace Tests\Feature;

use App\Jobs\ReleaseWifiAccessJob;
use App\Models\Client;
use App\Models\ClientDevice;
use App\Models\ControllerSetting;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Models\WifiSession;
use App\Services\OmadaService;
use App\Support\PortalTokenService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_payment_page_exposes_qr_download_endpoint(): void
    {
        $payment = $this->createPendingPayment();
        $paymentToken = $this->issuePaymentToken($payment);

        $this->get("/payments/{$paymentToken}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/PaymentStatus')
                ->where('qrDownloadEndpoint', route('payments.qr.download', [
                    'paymentToken' => $paymentToken,
                ]))
            );
    }

    public function test_qr_download_endpoint_streams_data_url_as_attachment(): void
    {
        $payment = $this->createPendingPayment([
            'qr_reference' => 'qr-download-test',
            'qr_image_url' => 'data:image/png;base64,'.base64_encode('qr-image-bytes'),
        ]);

        $response = $this->get("/payments/{$this->issuePaymentToken($payment)}/download-qr");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Content-Disposition', 'attachment; filename="brucke-qr-'.$payment->id.'.png"');
        $this->assertSame('qr-image-bytes', $response->getContent());
    }

    public function test_qr_download_endpoint_streams_remote_qr_as_attachment(): void
    {
        $payment = $this->createPendingPayment([
            'qr_reference' => 'qr-download-remote-test',
            'qr_image_url' => 'https://example.com/qr.png',
        ]);

        Http::fake([
            'https://example.com/qr.png' => Http::response('remote-qr-image', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $response = $this->get("/payments/{$this->issuePaymentToken($payment)}/download-qr");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Content-Disposition', 'attachment; filename="brucke-qr-'.$payment->id.'.png"');
        $this->assertSame('remote-qr-image', $response->getContent());
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

        Bus::assertDispatched(ReleaseWifiAccessJob::class, fn (ReleaseWifiAccessJob $job) => $job->sessionId === $payment->wifiSession->id && $job->path === 'webhook');
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
        Bus::assertDispatched(ReleaseWifiAccessJob::class, fn (ReleaseWifiAccessJob $job) => $job->sessionId === $payment->wifiSession->id && $job->path === 'manual_recheck');
    }

    public function test_paid_same_device_renewal_extends_existing_active_session_instead_of_creating_parallel_active_rows(): void
    {
        Bus::fake();
        config()->set('services.paymongo.webhook_secret', 'whsec_test_123');

        $site = Site::query()->create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
        ]);

        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'last_connected_at' => now(),
        ]);

        $activePlan = Plan::query()->create([
            'name' => '30 Minutes',
            'price' => 15,
            'duration_minutes' => 30,
        ]);

        $renewalPlan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $activeEnd = now()->addMinutes(20);

        $activeSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'plan_id' => $activePlan->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'amount_paid' => $activePlan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => $activeEnd,
        ]);

        $renewalSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'plan_id' => $renewalPlan->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'amount_paid' => $renewalPlan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
            'extends_session_id' => $activeSession->id,
        ]);

        $payment = Payment::query()->create([
            'wifi_session_id' => $renewalSession->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'RENEWAL123',
            'status' => Payment::STATUS_AWAITING_PAYMENT,
            'paymongo_payment_intent_id' => 'pi_test_renewal_123',
            'paymongo_payment_method_id' => 'pm_test_renewal_123',
            'qr_reference' => 'code_test_renewal_123',
            'qr_image_url' => 'data:image/png;base64,renewal',
            'qr_expires_at' => now()->addMinutes(30),
            'amount' => $renewalPlan->price,
            'currency' => 'PHP',
            'raw_response' => ['seed' => true],
        ]);

        $payload = json_encode([
            'data' => [
                'id' => 'evt_test_renewal_123',
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.paid',
                    'data' => [
                        'id' => 'pay_test_renewal_123',
                        'type' => 'payment',
                        'attributes' => [
                            'payment_intent_id' => 'pi_test_renewal_123',
                            'external_reference_number' => 'RENEWAL123',
                            'paid_at' => now()->timestamp,
                            'source' => [
                                'provider' => [
                                    'code_id' => 'code_test_renewal_123',
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
        $renewalSession->refresh();
        $activeSession->refresh();
        $expectedEnd = $activeEnd->copy()->addMinutes($renewalPlan->duration_minutes);

        $this->assertSame(Payment::STATUS_PAID, $payment->payment_status);
        $this->assertSame(WifiSession::SESSION_STATUS_MERGED, $renewalSession->session_status);
        $this->assertSame($activeSession->id, $renewalSession->merged_into_session_id);
        $this->assertTrue($activeSession->is_active);
        $this->assertSame($expectedEnd->format('Y-m-d H:i:s'), $activeSession->end_time->format('Y-m-d H:i:s'));
        $this->assertSame(1, WifiSession::query()->where('client_id', $client->id)->where('is_active', true)->count());
        Bus::assertNotDispatched(ReleaseWifiAccessJob::class);
    }

    public function test_duplicate_webhook_and_manual_recheck_do_not_double_extend_same_device_renewal(): void
    {
        Bus::fake();
        config()->set('services.paymongo.webhook_secret', 'whsec_test_123');
        config()->set('services.paymongo.secret_key', 'sk_test_123');
        config()->set('services.paymongo.base_url', 'https://api.paymongo.com/v1');

        [$activeSession, $renewalSession, $payment, $renewalPlan, $activeEnd] = $this->createRenewalPaymentScenario();

        $payload = $this->renewalPaidWebhookPayload('pi_test_renewal_123', 'pay_test_renewal_123', 'evt_test_renewal_123');
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_123');

        $this->call(
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
        )->assertOk();

        $activeSession->refresh();
        $expectedEnd = $activeEnd->copy()->addMinutes($renewalPlan->duration_minutes);
        $this->assertSame($expectedEnd->format('Y-m-d H:i:s'), $activeSession->end_time->format('Y-m-d H:i:s'));

        $duplicatePayload = $this->renewalPaidWebhookPayload('pi_test_renewal_123', 'pay_test_renewal_123', 'evt_test_renewal_456');
        $duplicateSignature = hash_hmac('sha256', "{$timestamp}.{$duplicatePayload}", 'whsec_test_123');

        $this->call(
            'POST',
            '/api/paymongo/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Paymongo-Signature' => "t={$timestamp},te={$duplicateSignature}",
            ],
            $duplicatePayload
        )->assertOk();

        Http::fake([
            'https://api.paymongo.com/v1/payment_intents/pi_test_renewal_123' => Http::response([
                'data' => [
                    'id' => 'pi_test_renewal_123',
                    'attributes' => [
                        'status' => 'succeeded',
                        'payments' => [[
                            'id' => 'pay_test_renewal_123',
                            'type' => 'payment',
                            'attributes' => [
                                'payment_intent_id' => 'pi_test_renewal_123',
                                'paid_at' => now()->timestamp,
                                'source' => [
                                    'provider' => [
                                        'code_id' => 'code_test_renewal_123',
                                    ],
                                ],
                            ],
                        ]],
                    ],
                ],
            ]),
        ]);

        $this
            ->withSession(['_token' => 'test-token'])
            ->withHeader('X-CSRF-TOKEN', 'test-token')
            ->postJson("/payments/{$this->issuePaymentToken($payment)}/recheck")
            ->assertOk()
            ->assertJsonPath('data.wifi_session_status', WifiSession::SESSION_STATUS_MERGED);

        $activeSession->refresh();
        $renewalSession->refresh();

        $this->assertSame($expectedEnd->format('Y-m-d H:i:s'), $activeSession->end_time->format('Y-m-d H:i:s'));
        $this->assertSame(WifiSession::SESSION_STATUS_MERGED, $renewalSession->session_status);
        Bus::assertNotDispatched(ReleaseWifiAccessJob::class);
    }

    public function test_failed_extension_payment_leaves_current_active_session_unchanged(): void
    {
        [$activeSession, $renewalSession, $payment, $renewalPlan, $activeEnd] = $this->createRenewalPaymentScenario();

        $payment->update([
            'status' => Payment::STATUS_FAILED,
            'failure_reason' => 'Payment failed.',
        ]);

        $renewalSession->update([
            'payment_status' => WifiSession::PAYMENT_STATUS_FAILED,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
        ]);

        $activeSession->refresh();
        $renewalSession->refresh();

        $this->assertTrue($activeSession->is_active);
        $this->assertSame(WifiSession::SESSION_STATUS_ACTIVE, $activeSession->session_status);
        $this->assertSame($activeEnd->format('Y-m-d H:i:s'), $activeSession->end_time->format('Y-m-d H:i:s'));
        $this->assertSame(WifiSession::PAYMENT_STATUS_FAILED, $renewalSession->payment_status);
        $this->assertSame(WifiSession::SESSION_STATUS_PENDING_PAYMENT, $renewalSession->session_status);
        $this->assertNull($renewalSession->merged_into_session_id);
    }

    public function test_db_guard_blocks_multiple_active_sessions_for_same_client(): void
    {
        $session = $this->createWifiSession([
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(5),
            'end_time' => now()->addMinutes(55),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        WifiSession::query()->create([
            'client_id' => $session->client_id,
            'plan_id' => $session->plan_id,
            'site_id' => $session->site_id,
            'mac_address' => $session->mac_address,
            'ap_mac' => '22:33:44:55:66:77',
            'ap_name' => 'South Pole AP',
            'ssid_name' => 'Guest WiFi',
            'radio_id' => 2,
            'client_ip' => '192.168.20.11',
            'amount_paid' => 25,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinute(),
            'end_time' => now()->addMinutes(60),
        ]);
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

        $job = new ReleaseWifiAccessJob($payment->wifiSession->id, 'webhook');
        $job->handle($this->app->make(\App\Services\WifiSessionReleaseService::class));

        $payment->wifiSession->refresh();

        $this->assertTrue($payment->wifiSession->is_active);
        $this->assertSame(WifiSession::SESSION_STATUS_ACTIVE, $payment->wifiSession->session_status);
        $this->assertSame(WifiSession::RELEASE_STATUS_SUCCEEDED, $payment->wifiSession->release_status);
        $this->assertSame('webhook', $payment->wifiSession->released_by_path);
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

        $job = new ReleaseWifiAccessJob($payment->wifiSession->id, 'webhook');
        $job->handle($this->app->make(\App\Services\WifiSessionReleaseService::class));

        $payment->wifiSession->refresh();

        $this->assertFalse($payment->wifiSession->is_active);
        $this->assertSame(WifiSession::SESSION_STATUS_RELEASE_FAILED, $payment->wifiSession->session_status);
        $this->assertSame(WifiSession::RELEASE_STATUS_MANUAL_REQUIRED, $payment->wifiSession->release_status);
        $this->assertSame(WifiSession::RELEASE_OUTCOME_NON_RETRYABLE_VALIDATION_FAILURE, $payment->wifiSession->release_outcome_type);
        $this->assertSame('Omada authorization failed.', $payment->wifiSession->release_failure_reason);
        $this->assertSame('Omada authorization failed.', $payment->wifiSession->last_release_error);
        $this->assertSame(1, $payment->wifiSession->release_attempt_count);
    }

    public function test_duplicate_release_attempt_does_not_double_authorize_session(): void
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

        $firstJob = new ReleaseWifiAccessJob($payment->wifiSession->id, 'webhook');
        $firstJob->handle($this->app->make(\App\Services\WifiSessionReleaseService::class));

        $payment->wifiSession->refresh();
        $originalEndTime = $payment->wifiSession->end_time?->copy();

        $secondJob = new ReleaseWifiAccessJob($payment->wifiSession->id, 'manual_recheck');
        $secondJob->handle($this->app->make(\App\Services\WifiSessionReleaseService::class));

        $payment->wifiSession->refresh();

        $this->assertTrue($payment->wifiSession->is_active);
        $this->assertSame(WifiSession::RELEASE_STATUS_SUCCEEDED, $payment->wifiSession->release_status);
        $this->assertSame($originalEndTime?->format('Y-m-d H:i:s'), $payment->wifiSession->end_time?->format('Y-m-d H:i:s'));
        $this->assertSame(1, WifiSession::query()->where('client_id', $payment->wifiSession->client_id)->where('is_active', true)->count());
    }

    public function test_retryable_release_failure_can_be_retried_and_later_succeed(): void
    {
        Bus::fake();
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
            ->andThrow(new \RuntimeException('Omada request failed for [/controller/api/v2/hotspot/extPortal/auth] with HTTP 500.'));
        $this->app->instance(OmadaService::class, $omadaService);

        $job = new ReleaseWifiAccessJob($payment->wifiSession->id, 'webhook');
        $job->handle($this->app->make(\App\Services\WifiSessionReleaseService::class));

        $payment->wifiSession->refresh();

        $this->assertSame(WifiSession::RELEASE_STATUS_UNCERTAIN, $payment->wifiSession->release_status);
        $this->assertTrue($payment->wifiSession->controller_state_uncertain);
        $this->assertSame(1, $payment->wifiSession->release_attempt_count);
        Bus::assertDispatched(ReleaseWifiAccessJob::class, fn (ReleaseWifiAccessJob $job) => $job->sessionId === $payment->wifiSession->id && $job->path === 'automatic_retry');

        Bus::fake();
        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->andReturn([
                'errorCode' => 0,
                'msg' => 'Success.',
            ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $retryJob = new ReleaseWifiAccessJob($payment->wifiSession->id, 'automatic_retry', [
            'retry_attempt' => 1,
        ]);
        $retryJob->handle($this->app->make(\App\Services\WifiSessionReleaseService::class));

        $payment->wifiSession->refresh();

        $this->assertTrue($payment->wifiSession->is_active);
        $this->assertSame(WifiSession::SESSION_STATUS_ACTIVE, $payment->wifiSession->session_status);
        $this->assertSame(WifiSession::RELEASE_STATUS_SUCCEEDED, $payment->wifiSession->release_status);
        $this->assertSame('automatic_retry', $payment->wifiSession->released_by_path);
        $this->assertSame(2, $payment->wifiSession->release_attempt_count);
        $this->assertSame(1, WifiSession::query()->where('client_id', $payment->wifiSession->client_id)->where('is_active', true)->count());
    }

    public function test_admin_retry_cannot_queue_duplicate_release_jobs(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]);
        Cache::put(\App\Services\AutomationHealthService::QUEUE_WORKER_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addDay());
        $payment = $this->createPaidPayment();
        $payment->wifiSession->forceFill([
            'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
            'release_status' => WifiSession::RELEASE_STATUS_FAILED,
            'last_release_error' => 'Temporary controller failure.',
            'release_failure_reason' => 'Temporary controller failure.',
        ])->save();

        $this->actingAs($admin)
            ->post("/admin/sessions/{$payment->wifiSession->id}/retry-release")
            ->assertRedirect('/admin/sessions');

        $this->actingAs($admin)
            ->post("/admin/sessions/{$payment->wifiSession->id}/retry-release")
            ->assertRedirect('/admin/sessions');

        $payment->wifiSession->refresh();

        $this->assertSame(WifiSession::RELEASE_STATUS_PENDING, $payment->wifiSession->release_status);
        Bus::assertDispatchedTimes(ReleaseWifiAccessJob::class, 1);
        Bus::assertDispatched(ReleaseWifiAccessJob::class, fn (ReleaseWifiAccessJob $job) => $job->sessionId === $payment->wifiSession->id && $job->path === 'admin_retry');
    }

    public function test_successful_admin_retry_marks_session_released_exactly_once(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]);
        Cache::put(\App\Services\AutomationHealthService::QUEUE_WORKER_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addDay());

        $payment = $this->createPaidPayment();
        $payment->wifiSession->forceFill([
            'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
            'release_status' => WifiSession::RELEASE_STATUS_FAILED,
            'last_release_error' => 'Temporary controller failure.',
            'release_failure_reason' => 'Temporary controller failure.',
        ])->save();

        $this->actingAs($admin)
            ->post("/admin/sessions/{$payment->wifiSession->id}/retry-release")
            ->assertRedirect('/admin/sessions');

        Bus::assertDispatchedTimes(ReleaseWifiAccessJob::class, 1);

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->andReturn([
                'errorCode' => 0,
                'msg' => 'Success.',
            ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $retryJob = new ReleaseWifiAccessJob($payment->wifiSession->id, 'admin_retry', [
            'triggered_by_user_id' => $admin->id,
        ]);
        $retryJob->handle($this->app->make(\App\Services\WifiSessionReleaseService::class));

        $payment->wifiSession->refresh();

        $this->assertTrue($payment->wifiSession->is_active);
        $this->assertSame(WifiSession::RELEASE_STATUS_SUCCEEDED, $payment->wifiSession->release_status);
        $this->assertSame('admin_retry', $payment->wifiSession->released_by_path);
        $this->assertSame(1, WifiSession::query()->where('client_id', $payment->wifiSession->client_id)->where('is_active', true)->count());
    }

    public function test_paid_but_unreleased_session_is_support_visible_in_admin_sessions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $payment = $this->createPaidPayment();
        $payment->wifiSession->forceFill([
            'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
            'release_status' => WifiSession::RELEASE_STATUS_UNCERTAIN,
            'release_outcome_type' => WifiSession::RELEASE_OUTCOME_UNCERTAIN_CONTROLLER_STATE,
            'last_release_error' => 'Controller timed out during authorization.',
            'release_failure_reason' => 'Controller timed out during authorization.',
            'controller_state_uncertain' => true,
            'last_reconcile_result' => 'reconcile_failed',
        ])->save();

        $this->actingAs($admin)
            ->get('/admin/sessions')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Sessions')
                ->where('releaseRuntime.degraded', true)
                ->where('sessions.data.0.release_status', WifiSession::RELEASE_STATUS_UNCERTAIN)
                ->where('sessions.data.0.release_outcome_type', WifiSession::RELEASE_OUTCOME_UNCERTAIN_CONTROLLER_STATE)
                ->where('sessions.data.0.last_reconcile_result', 'reconcile_failed')
                ->where('sessions.data.0.last_release_error', 'Controller timed out during authorization.')
                ->where('sessions.data.0.controller_state_uncertain', true)
            );
    }

    public function test_uncertain_release_can_be_reconciled_into_succeeded_when_controller_confirms_authorization(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $payment = $this->createPaidPayment();
        $payment->wifiSession->forceFill([
            'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
            'release_status' => WifiSession::RELEASE_STATUS_UNCERTAIN,
            'release_outcome_type' => WifiSession::RELEASE_OUTCOME_UNCERTAIN_CONTROLLER_STATE,
            'controller_state_uncertain' => true,
            'start_time' => now(),
            'end_time' => now()->addMinutes(60),
        ])->save();

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('inspectClientAuthorization')
            ->once()
            ->andReturn([
                'found' => true,
                'authorized' => true,
                'connected' => true,
                'raw_status' => 'connected',
                'raw_portal_status' => 'authorized',
                'source' => 'controller_clients',
            ]);
        $omadaService->shouldReceive('authorizeClient')->never();
        $this->app->instance(OmadaService::class, $omadaService);

        $this->app->make(\App\Services\WifiSessionReleaseService::class)
            ->reconcileSession($payment->wifiSession->id, 'manual_reconcile');

        $payment->wifiSession->refresh();

        $this->assertTrue($payment->wifiSession->is_active);
        $this->assertSame(WifiSession::RELEASE_STATUS_SUCCEEDED, $payment->wifiSession->release_status);
        $this->assertSame(WifiSession::RELEASE_OUTCOME_SUCCESS, $payment->wifiSession->release_outcome_type);
        $this->assertSame('reconcile_confirmed', $payment->wifiSession->released_by_path);
        $this->assertSame('authorized_in_controller', $payment->wifiSession->last_reconcile_result);
    }

    public function test_stale_in_progress_session_is_detected_and_moved_out_of_limbo_safely(): void
    {
        Bus::fake();
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $payment = $this->createPaidPayment();
        $payment->wifiSession->forceFill([
            'release_status' => WifiSession::RELEASE_STATUS_IN_PROGRESS,
            'last_release_attempt_at' => now()->subMinutes(10),
            'release_attempt_count' => 1,
            'start_time' => now(),
            'end_time' => now()->addMinutes(60),
            'release_metadata' => [
                'last_attempt' => [
                    'planned_start_time' => now()->toIso8601String(),
                    'planned_end_time' => now()->addMinutes(60)->toIso8601String(),
                ],
            ],
        ])->save();

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('inspectClientAuthorization')
            ->once()
            ->andReturn([
                'found' => false,
                'authorized' => false,
                'connected' => false,
                'raw_status' => null,
                'raw_portal_status' => null,
                'source' => 'controller_clients',
            ]);
        $this->app->instance(OmadaService::class, $omadaService);

        $session = $this->app->make(\App\Services\WifiSessionReleaseService::class)
            ->reconcileSession($payment->wifiSession->id, 'manual_reconcile');

        $session->refresh();

        $this->assertSame(WifiSession::RELEASE_STATUS_PENDING, $session->release_status);
        $this->assertNotNull($session->release_stuck_at);
        $this->assertSame('not_authorized_in_controller', $session->last_reconcile_result);
        Bus::assertDispatched(ReleaseWifiAccessJob::class, fn (ReleaseWifiAccessJob $job) => $job->sessionId === $session->id && $job->path === 'reconcile_retry');
    }

    public function test_exhausted_retries_end_in_manual_required_state(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]);

        $payment = $this->createPaidPayment();
        $payment->wifiSession->forceFill([
            'release_attempt_count' => 2,
        ])->save();

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('authorizeClient')
            ->once()
            ->andThrow(new \App\Exceptions\OmadaOperationException(
                \App\Exceptions\OmadaOperationException::CATEGORY_TIMEOUT,
                'Authorization timed out.'
            ));
        $this->app->instance(OmadaService::class, $omadaService);

        $job = new ReleaseWifiAccessJob($payment->wifiSession->id, 'automatic_retry', [
            'retry_attempt' => 3,
        ]);
        $job->handle($this->app->make(\App\Services\WifiSessionReleaseService::class));

        $payment->wifiSession->refresh();

        $this->assertSame(WifiSession::RELEASE_STATUS_MANUAL_REQUIRED, $payment->wifiSession->release_status);
        $this->assertSame(WifiSession::RELEASE_OUTCOME_MANUAL_FOLLOWUP_REQUIRED, $payment->wifiSession->release_outcome_type);
    }

    public function test_admin_retry_on_manual_required_session_remains_safe_and_idempotent(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'hotspot_operator_username' => 'operator',
            'hotspot_operator_password' => 'secret',
        ]);
        Cache::put(\App\Services\AutomationHealthService::QUEUE_WORKER_HEARTBEAT_CACHE_KEY, now()->toIso8601String(), now()->addDay());
        $payment = $this->createPaidPayment();
        $payment->wifiSession->forceFill([
            'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
            'release_status' => WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
            'release_outcome_type' => WifiSession::RELEASE_OUTCOME_MANUAL_FOLLOWUP_REQUIRED,
            'last_release_error' => 'Automation exhausted retries.',
            'release_failure_reason' => 'Automation exhausted retries.',
        ])->save();

        $this->actingAs($admin)
            ->post("/admin/sessions/{$payment->wifiSession->id}/retry-release")
            ->assertRedirect('/admin/sessions');

        $this->actingAs($admin)
            ->post("/admin/sessions/{$payment->wifiSession->id}/retry-release")
            ->assertRedirect('/admin/sessions');

        $payment->wifiSession->refresh();

        $this->assertSame(WifiSession::RELEASE_STATUS_PENDING, $payment->wifiSession->release_status);
        Bus::assertDispatchedTimes(ReleaseWifiAccessJob::class, 1);
    }

    public function test_reconciliation_does_not_double_authorize_already_released_client(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $payment = $this->createPaidPayment();
        $payment->wifiSession->forceFill([
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'release_status' => WifiSession::RELEASE_STATUS_SUCCEEDED,
            'release_outcome_type' => WifiSession::RELEASE_OUTCOME_SUCCESS,
        ])->save();

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('inspectClientAuthorization')->never();
        $omadaService->shouldReceive('authorizeClient')->never();
        $this->app->instance(OmadaService::class, $omadaService);

        $this->app->make(\App\Services\WifiSessionReleaseService::class)
            ->reconcileSession($payment->wifiSession->id, 'manual_reconcile');

        $payment->wifiSession->refresh();

        $this->assertSame(WifiSession::RELEASE_STATUS_SUCCEEDED, $payment->wifiSession->release_status);
        $this->assertTrue($payment->wifiSession->is_active);
    }

    public function test_reconcile_command_processes_uncertain_sessions(): void
    {
        ControllerSetting::query()->create([
            'controller_name' => 'Pilot Controller',
            'base_url' => 'https://localhost:8043',
            'username' => 'admin',
            'password' => 'secret',
        ]);

        $payment = $this->createPaidPayment();
        $payment->wifiSession->forceFill([
            'session_status' => WifiSession::SESSION_STATUS_RELEASE_FAILED,
            'release_status' => WifiSession::RELEASE_STATUS_UNCERTAIN,
            'release_outcome_type' => WifiSession::RELEASE_OUTCOME_UNCERTAIN_CONTROLLER_STATE,
            'controller_state_uncertain' => true,
            'start_time' => now(),
            'end_time' => now()->addMinutes(60),
        ])->save();

        $omadaService = Mockery::mock(OmadaService::class);
        $omadaService->shouldReceive('inspectClientAuthorization')
            ->once()
            ->andReturn([
                'found' => true,
                'authorized' => true,
                'connected' => true,
                'raw_status' => 'connected',
                'raw_portal_status' => 'authorized',
                'source' => 'controller_clients',
            ]);
        $this->app->instance(OmadaService::class, $omadaService);

        Artisan::call('wifi:reconcile-releases', ['--limit' => 10]);

        $payment->wifiSession->refresh();

        $this->assertSame(WifiSession::RELEASE_STATUS_SUCCEEDED, $payment->wifiSession->release_status);
        $this->assertNotNull(Cache::get(\App\Services\WifiSessionReleaseService::RECONCILE_HEARTBEAT_CACHE_KEY));
    }

    public function test_admin_reconcile_release_action_requires_admin_access(): void
    {
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        $payment = $this->createPaidPayment();

        $this->post("/admin/sessions/{$payment->wifiSession->id}/reconcile-release")
            ->assertRedirect('/login');

        $this->actingAs($nonAdmin)
            ->post("/admin/sessions/{$payment->wifiSession->id}/reconcile-release")
            ->assertForbidden();
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
            'release_status' => WifiSession::RELEASE_STATUS_PENDING,
            'release_outcome_type' => null,
            'release_attempt_count' => 0,
            'last_release_attempt_at' => null,
            'last_release_error' => null,
            'controller_state_uncertain' => false,
            'released_at' => null,
            'last_reconciled_at' => null,
            'reconcile_attempt_count' => 0,
            'last_reconcile_result' => null,
            'release_stuck_at' => null,
            'released_by_path' => null,
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

        return WifiSession::query()->create(array_merge([
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

    private function createRenewalPaymentScenario(): array
    {
        $site = Site::query()->create([
            'name' => 'Main Branch',
            'slug' => 'main-branch',
        ]);

        $client = Client::query()->create([
            'name' => 'Juan Dela Cruz',
            'phone_number' => '09171234567',
            'pin' => bcrypt('1234'),
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'last_connected_at' => now(),
        ]);

        $activePlan = Plan::query()->create([
            'name' => '30 Minutes',
            'price' => 15,
            'duration_minutes' => 30,
        ]);

        $renewalPlan = Plan::query()->create([
            'name' => '1 Hour',
            'price' => 25,
            'duration_minutes' => 60,
        ]);

        $activeEnd = now()->addMinutes(20);

        $activeSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'plan_id' => $activePlan->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'amount_paid' => $activePlan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_PAID,
            'session_status' => WifiSession::SESSION_STATUS_ACTIVE,
            'is_active' => true,
            'start_time' => now()->subMinutes(10),
            'end_time' => $activeEnd,
        ]);

        $renewalSession = WifiSession::query()->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'plan_id' => $renewalPlan->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'amount_paid' => $renewalPlan->price,
            'payment_status' => WifiSession::PAYMENT_STATUS_AWAITING_PAYMENT,
            'session_status' => WifiSession::SESSION_STATUS_PENDING_PAYMENT,
            'is_active' => false,
            'extends_session_id' => $activeSession->id,
        ]);

        $payment = Payment::query()->create([
            'wifi_session_id' => $renewalSession->id,
            'provider' => Payment::PROVIDER_PAYMONGO,
            'payment_flow' => Payment::FLOW_QRPH,
            'reference_id' => 'RENEWAL123',
            'status' => Payment::STATUS_AWAITING_PAYMENT,
            'paymongo_payment_intent_id' => 'pi_test_renewal_123',
            'paymongo_payment_method_id' => 'pm_test_renewal_123',
            'qr_reference' => 'code_test_renewal_123',
            'qr_image_url' => 'data:image/png;base64,renewal',
            'qr_expires_at' => now()->addMinutes(30),
            'amount' => $renewalPlan->price,
            'currency' => 'PHP',
            'raw_response' => ['seed' => true],
        ]);

        return [$activeSession, $renewalSession, $payment, $renewalPlan, $activeEnd];
    }

    private function renewalPaidWebhookPayload(string $paymentIntentId, string $paymongoPaymentId, string $eventId): string
    {
        return json_encode([
            'data' => [
                'id' => $eventId,
                'type' => 'event',
                'attributes' => [
                    'type' => 'payment.paid',
                    'data' => [
                        'id' => $paymongoPaymentId,
                        'type' => 'payment',
                        'attributes' => [
                            'payment_intent_id' => $paymentIntentId,
                            'external_reference_number' => 'RENEWAL123',
                            'paid_at' => now()->timestamp,
                            'source' => [
                                'provider' => [
                                    'code_id' => 'code_test_renewal_123',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
