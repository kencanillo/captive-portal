<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\WifiSession;
use App\Services\WifiSessionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReleaseWifiAccessJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $paymentId,
    ) {
    }

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return "release-wifi-access:{$this->paymentId}";
    }

    public function handle(WifiSessionService $wifiSessionService): void
    {
        $payment = Payment::query()
            ->with(['wifiSession.plan', 'wifiSession.client', 'wifiSession.site', 'wifiSession.accessPoint'])
            ->findOrFail($this->paymentId);

        $session = $payment->wifiSession;

        if (! $session) {
            Log::warning('ReleaseWifiAccessJob skipped because payment has no WiFi session.', [
                'payment_id' => $this->paymentId,
            ]);

            return;
        }

        if ($payment->status !== Payment::STATUS_PAID) {
            Log::warning('ReleaseWifiAccessJob skipped because payment is not confirmed as paid.', [
                'payment_id' => $payment->id,
                'payment_status' => $payment->status,
            ]);

            return;
        }

        if ($session->session_status === WifiSession::SESSION_STATUS_ACTIVE && $session->is_active) {
            Log::info('ReleaseWifiAccessJob skipped because WiFi session is already active.', [
                'payment_id' => $payment->id,
                'wifi_session_id' => $session->id,
            ]);

            return;
        }

        Log::info('Attempting Omada release for paid WiFi session.', [
            'payment_id' => $payment->id,
            'wifi_session_id' => $session->id,
        ]);

        try {
            $wifiSessionService->activateSession($session);
        } catch (\Throwable $exception) {
            $wifiSessionService->markReleaseFailed($session, $exception->getMessage());

            Log::error('Omada release attempt failed.', [
                'payment_id' => $payment->id,
                'wifi_session_id' => $session->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
