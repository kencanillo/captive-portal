<?php

namespace App\Support\Release;

use App\Models\WifiSession;

class ReleaseOutcome
{
    public const TYPE_SUCCESS = 'success';
    public const TYPE_RETRYABLE_CONTROLLER_FAILURE = 'retryable_controller_failure';
    public const TYPE_RETRYABLE_TIMEOUT = 'retryable_timeout';
    public const TYPE_NON_RETRYABLE_CONFIGURATION_FAILURE = 'non_retryable_configuration_failure';
    public const TYPE_NON_RETRYABLE_VALIDATION_FAILURE = 'non_retryable_validation_failure';
    public const TYPE_UNCERTAIN_CONTROLLER_STATE = 'uncertain_controller_state';
    public const TYPE_MANUAL_FOLLOWUP_REQUIRED = 'manual_followup_required';

    public function __construct(
        public readonly string $type,
        public readonly string $releaseStatus,
        public readonly bool $retryable,
        public readonly bool $controllerStateUncertain,
        public readonly bool $manualFollowupRequired,
    ) {
    }

    public static function success(): self
    {
        return new self(
            self::TYPE_SUCCESS,
            WifiSession::RELEASE_STATUS_SUCCEEDED,
            false,
            false,
            false,
        );
    }

    public function withManualFollowup(): self
    {
        return new self(
            self::TYPE_MANUAL_FOLLOWUP_REQUIRED,
            WifiSession::RELEASE_STATUS_MANUAL_REQUIRED,
            false,
            $this->controllerStateUncertain,
            true,
        );
    }
}
