<?php

namespace Tests\Unit;

use App\Exceptions\OmadaOperationException;
use App\Exceptions\ReleaseOperationException;
use App\Models\WifiSession;
use App\Services\ReleaseOutcomeClassifier;
use App\Support\Release\ReleaseOutcome;
use PHPUnit\Framework\TestCase;

class ReleaseOutcomeClassifierTest extends TestCase
{
    public function test_it_maps_omada_timeout_to_stable_retryable_timeout_outcome(): void
    {
        $classifier = new ReleaseOutcomeClassifier();

        $outcome = $classifier->classify(new OmadaOperationException(
            OmadaOperationException::CATEGORY_TIMEOUT,
            'Controller timed out.'
        ));

        $this->assertSame(ReleaseOutcome::TYPE_RETRYABLE_TIMEOUT, $outcome->type);
        $this->assertSame(WifiSession::RELEASE_STATUS_UNCERTAIN, $outcome->releaseStatus);
        $this->assertTrue($outcome->retryable);
        $this->assertTrue($outcome->controllerStateUncertain);
    }

    public function test_it_maps_release_configuration_errors_to_manual_followup_configuration_outcome(): void
    {
        $classifier = new ReleaseOutcomeClassifier();

        $outcome = $classifier->classify(
            ReleaseOperationException::configuration('Controller settings are missing.')
        );

        $this->assertSame(ReleaseOutcome::TYPE_NON_RETRYABLE_CONFIGURATION_FAILURE, $outcome->type);
        $this->assertSame(WifiSession::RELEASE_STATUS_MANUAL_REQUIRED, $outcome->releaseStatus);
        $this->assertFalse($outcome->retryable);
        $this->assertTrue($outcome->manualFollowupRequired);
    }

    public function test_it_keeps_message_fallback_isolated_for_unknown_throwables(): void
    {
        $classifier = new ReleaseOutcomeClassifier();

        $outcome = $classifier->classify(new \RuntimeException('Timeout while contacting controller.'));

        $this->assertSame(ReleaseOutcome::TYPE_RETRYABLE_TIMEOUT, $outcome->type);
        $this->assertSame(WifiSession::RELEASE_STATUS_UNCERTAIN, $outcome->releaseStatus);
    }
}
