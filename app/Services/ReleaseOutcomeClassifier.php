<?php

namespace App\Services;

use App\Exceptions\OmadaOperationException;
use App\Exceptions\ReleaseOperationException;
use App\Models\WifiSession;
use App\Support\Release\ReleaseOutcome;
use Throwable;

class ReleaseOutcomeClassifier
{
    public function classify(Throwable $exception): ReleaseOutcome
    {
        if ($exception instanceof ReleaseOperationException) {
            return match ($exception->kind) {
                ReleaseOperationException::KIND_CONFIGURATION => new ReleaseOutcome(
                    ReleaseOutcome::TYPE_NON_RETRYABLE_CONFIGURATION_FAILURE,
                    WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
                    false,
                    false,
                    true,
                ),
                ReleaseOperationException::KIND_VALIDATION,
                ReleaseOperationException::KIND_POLICY => new ReleaseOutcome(
                    ReleaseOutcome::TYPE_NON_RETRYABLE_VALIDATION_FAILURE,
                    WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
                    false,
                    false,
                    true,
                ),
                default => $this->fallback($exception),
            };
        }

        if ($exception instanceof OmadaOperationException) {
            return match ($exception->category) {
                OmadaOperationException::CATEGORY_TIMEOUT => new ReleaseOutcome(
                    ReleaseOutcome::TYPE_RETRYABLE_TIMEOUT,
                    WifiSession::RELEASE_STATUS_UNCERTAIN,
                    true,
                    true,
                    false,
                ),
                OmadaOperationException::CATEGORY_CONTROLLER => new ReleaseOutcome(
                    ReleaseOutcome::TYPE_RETRYABLE_CONTROLLER_FAILURE,
                    WifiSession::RELEASE_STATUS_FAILED,
                    true,
                    false,
                    false,
                ),
                OmadaOperationException::CATEGORY_AUTHENTICATION,
                OmadaOperationException::CATEGORY_SSL,
                OmadaOperationException::CATEGORY_CONFIGURATION => new ReleaseOutcome(
                    ReleaseOutcome::TYPE_NON_RETRYABLE_CONFIGURATION_FAILURE,
                    WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
                    false,
                    false,
                    true,
                ),
                OmadaOperationException::CATEGORY_VALIDATION => new ReleaseOutcome(
                    ReleaseOutcome::TYPE_NON_RETRYABLE_VALIDATION_FAILURE,
                    WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
                    false,
                    false,
                    true,
                ),
                default => $this->fallback($exception),
            };
        }

        return $this->fallback($exception);
    }

    public function escalateToManualFollowup(ReleaseOutcome $outcome): ReleaseOutcome
    {
        return $outcome->withManualFollowup();
    }

    public function uncertainControllerState(): ReleaseOutcome
    {
        return new ReleaseOutcome(
            ReleaseOutcome::TYPE_UNCERTAIN_CONTROLLER_STATE,
            WifiSession::RELEASE_STATUS_UNCERTAIN,
            true,
            true,
            false,
        );
    }

    public function retryableControllerFailure(): ReleaseOutcome
    {
        return new ReleaseOutcome(
            ReleaseOutcome::TYPE_RETRYABLE_CONTROLLER_FAILURE,
            WifiSession::RELEASE_STATUS_FAILED,
            true,
            false,
            false,
        );
    }

    private function fallback(Throwable $exception): ReleaseOutcome
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return new ReleaseOutcome(
                ReleaseOutcome::TYPE_RETRYABLE_TIMEOUT,
                WifiSession::RELEASE_STATUS_UNCERTAIN,
                true,
                true,
                false,
            );
        }

        if (str_contains($message, 'http 401') || str_contains($message, 'http 403')
            || str_contains($message, 'login failed') || str_contains($message, 'unauthorized')
            || str_contains($message, 'ssl certificate') || str_contains($message, 'curl error 60')) {
            return new ReleaseOutcome(
                ReleaseOutcome::TYPE_NON_RETRYABLE_CONFIGURATION_FAILURE,
                WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
                false,
                false,
                true,
            );
        }

        if (str_contains($message, 'requires client mac')
            || str_contains($message, 'session end time is missing')
            || str_contains($message, 'requires a site')
            || str_contains($message, 'another device already has active internet access')
            || str_contains($message, 'authorization failed')) {
            return new ReleaseOutcome(
                ReleaseOutcome::TYPE_NON_RETRYABLE_VALIDATION_FAILURE,
                WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
                false,
                false,
                true,
            );
        }

        return new ReleaseOutcome(
            ReleaseOutcome::TYPE_UNCERTAIN_CONTROLLER_STATE,
            WifiSession::RELEASE_STATUS_UNCERTAIN,
            true,
            true,
            false,
        );
    }
}
