<?php

namespace App\Services;

use App\Models\WifiSession;
use App\Support\MacAddress;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WifiSessionAuthorizationService
{
    public function findActiveSessionForMac(string $macAddress, array $networkContext = []): ?WifiSession
    {
        $normalizedMac = MacAddress::normalizeForStorage($macAddress);

        if ($normalizedMac === null) {
            Log::warning('Active session lookup rejected because the MAC address is invalid.', [
                'mac_address' => $macAddress,
            ]);

            return null;
        }

        /** @var WifiSession|null $candidate */
        $candidate = WifiSession::query()
            ->with(['client:id,name,phone_number', 'plan:id,name,duration_minutes', 'site:id,name,slug'])
            ->whereRaw('LOWER(mac_address) = ?', [$normalizedMac])
            ->orderByDesc('end_time')
            ->orderByDesc('id')
            ->first();

        $resolvedSession = $candidate
            && $this->sessionIsCurrentlyAuthoritativeForMac($candidate, $normalizedMac, $networkContext)
                ? $candidate
                : null;

        Log::info('Active-session lookup completed.', [
            'mac_address' => $normalizedMac,
            'site_name' => $this->normalizeText($networkContext['site_name'] ?? null),
            'ssid_name' => $this->normalizeText($networkContext['ssid_name'] ?? null),
            'matched_session_id' => $resolvedSession ? $resolvedSession->id : null,
            'reason' => $this->lookupReason($candidate, $normalizedMac, $networkContext),
        ]);

        return $resolvedSession;
    }

    public function sessionIsCurrentlyAuthoritativeForMac(WifiSession $session, string $macAddress, array $networkContext = []): bool
    {
        if (! MacAddress::equals($session->mac_address, $macAddress)) {
            return false;
        }

        if (! $session->is_active) {
            return false;
        }

        if ($session->payment_status !== WifiSession::PAYMENT_STATUS_PAID) {
            return false;
        }

        if ($session->session_status !== WifiSession::SESSION_STATUS_ACTIVE) {
            return false;
        }

        if (! $session->end_time || ! $session->end_time->isFuture()) {
            return false;
        }

        if (! $this->siteMatches($session, $networkContext)) {
            return false;
        }

        if (! $this->ssidMatches($session, $networkContext)) {
            return false;
        }

        return true;
    }

    public function markSessionAuthorized(WifiSession $session, string $source, ?\DateTimeInterface $seenAt = null): WifiSession
    {
        $timestamp = $seenAt ? Carbon::instance($seenAt) : now();

        $session->forceFill([
            'authorized_at' => $session->authorized_at ?? $timestamp,
            'deauthorized_at' => null,
            'authorization_source' => Str::limit($source, 50, ''),
            'last_controller_seen_at' => $timestamp,
        ])->save();

        return $session->refresh();
    }

    public function markSessionDeauthorized(WifiSession $session, string $source, ?\DateTimeInterface $seenAt = null): WifiSession
    {
        $timestamp = $seenAt ? Carbon::instance($seenAt) : now();

        $session->forceFill([
            'deauthorized_at' => $timestamp,
            'authorization_source' => Str::limit($source, 50, ''),
            'last_controller_seen_at' => $session->last_controller_seen_at ?? $timestamp,
        ])->save();

        return $session->refresh();
    }

    public function markSessionDeauthorizationPending(WifiSession $session, string $source): WifiSession
    {
        $session->forceFill([
            'deauthorized_at' => null,
            'authorization_source' => Str::limit($source, 50, ''),
        ])->save();

        return $session->refresh();
    }

    private function lookupReason(?WifiSession $candidate, string $macAddress, array $networkContext): string
    {
        if ($candidate === null) {
            return 'no_session_for_mac';
        }

        if (! MacAddress::equals($candidate->mac_address, $macAddress)) {
            return 'mac_mismatch';
        }

        if (! $candidate->is_active) {
            return 'inactive_session';
        }

        if ($candidate->payment_status !== WifiSession::PAYMENT_STATUS_PAID) {
            return 'payment_not_paid';
        }

        if ($candidate->session_status !== WifiSession::SESSION_STATUS_ACTIVE) {
            return 'session_not_active';
        }

        if (! $candidate->end_time || ! $candidate->end_time->isFuture()) {
            return 'session_expired';
        }

        if (! $this->siteMatches($candidate, $networkContext)) {
            return 'site_mismatch';
        }

        if (! $this->ssidMatches($candidate, $networkContext)) {
            return 'ssid_mismatch';
        }

        return 'matched';
    }

    private function siteMatches(WifiSession $session, array $networkContext): bool
    {
        $requestedSite = $this->normalizeText($networkContext['site_name'] ?? $networkContext['site_identifier'] ?? null);

        if ($requestedSite === null) {
            return true;
        }

        $knownSites = array_filter([
            $this->normalizeText($session->site?->name),
            $this->normalizeText($session->site?->slug),
            $this->normalizeText($session->getAttribute('site_name')),
        ]);

        if ($knownSites === []) {
            return true;
        }

        foreach ($knownSites as $knownSite) {
            if ($knownSite === $requestedSite || Str::slug($knownSite) === Str::slug($requestedSite)) {
                return true;
            }
        }

        return false;
    }

    private function ssidMatches(WifiSession $session, array $networkContext): bool
    {
        $requestedSsid = $this->normalizeText($networkContext['ssid_name'] ?? null);
        $knownSsid = $this->normalizeText($session->ssid_name);

        if ($requestedSsid === null || $knownSsid === null) {
            return true;
        }

        return $requestedSsid === $knownSsid;
    }

    private function normalizeText(?string $value): ?string
    {
        $normalized = trim(mb_strtolower((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
