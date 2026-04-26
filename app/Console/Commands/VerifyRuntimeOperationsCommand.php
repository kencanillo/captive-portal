<?php

namespace App\Console\Commands;

use App\Services\OperationalVerificationService;
use Illuminate\Console\Command;

class VerifyRuntimeOperationsCommand extends Command
{
    protected $signature = 'ops:verify-runtime {--json : Render the verification payload as JSON}';

    protected $description = 'Run a safe operational verification of controller connectivity and critical runtime automation freshness.';

    public function handle(OperationalVerificationService $operationalVerificationService): int
    {
        $result = $operationalVerificationService->verify();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return $result['overall_status'] === OperationalVerificationService::STATUS_FAIL
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->info('Operational verification completed.');
        $this->line('Overall status: '.$result['overall_status']);

        foreach ($result['checks'] as $check) {
            $this->line(sprintf('[%s] %s: %s', strtoupper($check['status']), $check['label'], $check['summary']));
        }

        return $result['overall_status'] === OperationalVerificationService::STATUS_FAIL
            ? self::FAILURE
            : self::SUCCESS;
    }
}
