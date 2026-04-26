<?php

namespace App\Console\Commands;

use App\Models\ControllerSetting;
use App\Models\WifiSession;
use App\Services\OmadaService;
use App\Services\WifiSessionAuthorizationService;
use App\Services\WifiSessionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileAuthorizedOmadaClientsCommand extends Command
{
    protected $signature = 'omada:reconcile-authorized-clients';

    protected $description = 'Deauthorize controller clients that no longer have a valid local active WiFi session.';

    public function handle(
        OmadaService $omadaService,
        WifiSessionAuthorizationService $wifiSessionAuthorizationService,
        WifiSessionService $wifiSessionService,
    ): int {
        $settings = ControllerSetting::query()->first();

        if (! $settings) {
            $this->info('Skipping Omada authorized-client reconciliation because no controller settings exist yet.');

            return self::SUCCESS;
        }

        try {
            $authorizedClients = $omadaService->listAuthorizedClients($settings);
        } catch (Throwable $exception) {
            Log::error('Omada authorized-client reconciliation failed before processing clients.', [
                'error' => $exception->getMessage(),
            ]);

            $this->error('Unable to fetch authorized Omada clients. Check controller credentials and logs.');

            return self::FAILURE;
        }

        $validCount = 0;
        $unknownDeauthorizedCount = 0;
        $expiredDeauthorizedCount = 0;
        $invalidLocalStateCount = 0;
        $apiFailureCount = 0;

        foreach ($authorizedClients as $controllerClient) {
            $macAddress = $controllerClient['mac_address'];
            $siteIdentifier = $controllerClient['site_identifier'] ?? $controllerClient['site_name'] ?? null;

            try {
                $activeSession = $wifiSessionAuthorizationService->findActiveSessionForMac($macAddress);

                if ($activeSession) {
                    $wifiSessionAuthorizationService->markSessionAuthorized($activeSession, 'controller_reconcile');
                    $validCount++;

                    continue;
                }

                $latestActiveLocalSession = WifiSession::query()
                    ->with('site')
                    ->whereRaw('LOWER(mac_address) = ?', [$macAddress])
                    ->where('is_active', true)
                    ->orderByDesc('end_time')
                    ->orderByDesc('id')
                    ->first();

                if ($latestActiveLocalSession && $latestActiveLocalSession->end_time?->isPast()) {
                    $wifiSessionService->expireSession($latestActiveLocalSession, $settings);
                    $expiredDeauthorizedCount++;

                    continue;
                }

                if ($latestActiveLocalSession) {
                    $this->markLocalSessionInvalid($latestActiveLocalSession, $wifiSessionAuthorizationService);
                    $invalidLocalStateCount++;
                }

                $omadaService->deauthorizeClientByMac($settings, $macAddress, $siteIdentifier);
                $unknownDeauthorizedCount++;

                Log::warning('Deauthorized controller client without a valid local active session.', [
                    'mac_address' => $macAddress,
                    'site_identifier' => $siteIdentifier,
                    'raw_status' => $controllerClient['raw_status'] ?? null,
                    'raw_portal_status' => $controllerClient['raw_portal_status'] ?? null,
                ]);
            } catch (Throwable $exception) {
                $apiFailureCount++;

                Log::warning('Omada authorized-client reconciliation failed for one client.', [
                    'mac_address' => $macAddress,
                    'site_identifier' => $siteIdentifier,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        Log::info('Omada authorized-client reconciliation completed.', [
            'controller_client_count' => count($authorizedClients),
            'valid_client_count' => $validCount,
            'unknown_macs_deauthorized' => $unknownDeauthorizedCount,
            'expired_macs_deauthorized' => $expiredDeauthorizedCount,
            'invalid_local_state_count' => $invalidLocalStateCount,
            'api_failures' => $apiFailureCount,
        ]);

        $this->info(sprintf(
            'Reconciled %d authorized controller client(s): %d valid, %d unknown deauthorized, %d expired deauthorized, %d invalid local state, %d failures.',
            count($authorizedClients),
            $validCount,
            $unknownDeauthorizedCount,
            $expiredDeauthorizedCount,
            $invalidLocalStateCount,
            $apiFailureCount,
        ));

        return $apiFailureCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function markLocalSessionInvalid(
        WifiSession $session,
        WifiSessionAuthorizationService $wifiSessionAuthorizationService,
    ): void {
        $session->forceFill([
            'is_active' => false,
            'session_status' => $session->end_time?->isPast()
                ? WifiSession::SESSION_STATUS_EXPIRED
                : WifiSession::SESSION_STATUS_RELEASE_FAILED,
        ])->save();

        $wifiSessionAuthorizationService->markSessionDeauthorized($session, 'controller_reconcile_invalid_local_state');

        Log::warning('Local WiFi session was active without authoritative entitlement and was marked inactive.', [
            'wifi_session_id' => $session->id,
            'client_id' => $session->client_id,
            'mac_address' => $session->mac_address,
        ]);
    }
}
