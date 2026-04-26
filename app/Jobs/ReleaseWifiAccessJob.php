<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\WifiSession;
use App\Services\WifiSessionReleaseService;
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
        public readonly int $sessionId,
        public readonly string $path = 'payment_confirmation',
        public readonly array $context = [],
    ) {
    }

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return "release-wifi-access:{$this->sessionId}";
    }

    public function handle(WifiSessionReleaseService $wifiSessionReleaseService): void
    {
        $wifiSessionReleaseService->recordJobHeartbeat();

        $session = WifiSession::query()
            ->with(['latestPayment', 'plan', 'client', 'site', 'accessPoint'])
            ->find($this->sessionId);

        if (! $session) {
            Log::warning('ReleaseWifiAccessJob skipped because WiFi session no longer exists.', [
                'wifi_session_id' => $this->sessionId,
                'path' => $this->path,
            ]);

            return;
        }

        $payment = $session->latestPayment;

        if ($session->payment_status !== WifiSession::PAYMENT_STATUS_PAID
            || ($payment && $payment->status !== Payment::STATUS_PAID)) {
            Log::warning('ReleaseWifiAccessJob skipped because session payment is not confirmed as paid.', [
                'payment_id' => $payment?->id,
                'wifi_session_id' => $session->id,
                'payment_status' => $payment?->status,
                'session_payment_status' => $session->payment_status,
                'path' => $this->path,
            ]);

            return;
        }

        if ($session->session_status === WifiSession::SESSION_STATUS_ACTIVE
            && $session->is_active
            && $session->release_status === WifiSession::RELEASE_STATUS_SUCCEEDED) {
            Log::info('ReleaseWifiAccessJob skipped because WiFi session is already released.', [
                'payment_id' => $payment?->id,
                'wifi_session_id' => $session->id,
                'path' => $this->path,
            ]);

            return;
        }

        Log::info('Attempting Omada release for paid WiFi session.', [
            'payment_id' => $payment?->id,
            'wifi_session_id' => $session->id,
            'path' => $this->path,
        ]);

        $releasedSession = $wifiSessionReleaseService->attemptRelease($session->id, $this->path, $this->context);

        Log::info('Omada release attempt completed.', [
            'payment_id' => $payment?->id,
            'wifi_session_id' => $releasedSession->id,
            'path' => $this->path,
            'release_status' => $releasedSession->release_status,
            'session_status' => $releasedSession->session_status,
        ]);
    }
}
