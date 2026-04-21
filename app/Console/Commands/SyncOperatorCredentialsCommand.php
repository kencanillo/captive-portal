<?php

namespace App\Console\Commands;

use App\Services\OperatorCredentialValidator;
use App\Services\OperatorCredentialSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncOperatorCredentialsCommand extends Command
{
    protected $signature = 'app:sync-operator-credentials {--force} {--validate-only} {--no-omada}';

    protected $description = 'Validate and sync operator credentials with naming conventions and Omada controller';

    public function handle(): int
    {
        $this->info('Starting operator credential validation and sync...');

        if ($this->option('validate-only')) {
            return $this->validateOnly();
        }

        return $this->validateAndSync();
    }

    private function validateOnly(): int
    {
        $this->line('🔍 Validating operator credentials...');

        $validationResults = OperatorCredentialValidator::validateAllOperators();

        if (empty($validationResults)) {
            $this->info('✅ All operator credentials are valid!');
            return 0;
        }

        $this->error('❌ Found credential validation issues:');
        foreach ($validationResults as $operatorName => $issues) {
            $this->line("  Operator: {$operatorName}");
            foreach ($issues as $issue) {
                $severity = $issue['severity'] === 'error' ? 'ERROR' : 'WARNING';
                $this->line("    [{$severity}] {$issue['message']}");
                
                if (isset($issue['expected_username'])) {
                    $this->line("      Expected: {$issue['expected_username']}");
                }
                if (isset($issue['actual_username'])) {
                    $this->line("      Actual: {$issue['actual_username']}");
                }
            }
        }

        $this->line('');

        // Show Omada sync results
        if (!empty($validationResults['omada_sync'])) {
            $this->info('Omada Controller Sync Results:');
            foreach ($validationResults['omada_sync'] as $operatorName => $omadaResult) {
                $action = $omadaResult['action'];
                $message = $omadaResult['message'];
                
                switch ($action) {
                    case 'omada_sync':
                        $this->line("  2FA {$message}");
                        break;
                    case 'omada_failed':
                        $this->line("  2FA {$message}");
                        break;
                }
            }
        }

        $this->line('');
        $this->info('Run "php artisan app:sync-operator-credentials" to auto-fix these issues.');

        return 1;
    }

    private function validateAndSync(): int
    {
        $syncToOmada = !$this->option('no-omada');
        $this->line('Validating and syncing operator credentials...');
        $this->line('Omada Controller Sync: ' . ($syncToOmada ? 'ENABLED' : 'DISABLED'));

        $results = OperatorCredentialSyncService::validateAndSyncAll($syncToOmada);

        // Show validation results
        if (!empty($results['validation'])) {
            $this->error('❌ Validation Issues Found:');
            foreach ($results['validation'] as $operatorName => $issues) {
                $this->line("  Operator: {$operatorName}");
                foreach ($issues as $issue) {
                    $severity = $issue['severity'] === 'error' ? 'ERROR' : 'WARNING';
                    $this->line("    [{$severity}] {$issue['message']}");
                }
            }
        } else {
            $this->info('✅ All operator credentials passed validation');
        }

        $this->line('');

        // Show sync results
        $this->info('🔄 Sync Results:');
        foreach ($results['sync'] as $operatorName => $syncResult) {
            foreach ($syncResult as $result) {
                $action = $result['action'];
                $message = $result['message'];
                
                switch ($action) {
                    case 'created':
                        $this->line("  ✅ Created: {$message}");
                        break;
                    case 'updated':
                        $this->line("  🔄 Updated: {$message}");
                        if (isset($result['changes'])) {
                            foreach ($result['changes'] as $field => $change) {
                                $this->line("      {$field}: {$change['from']} → {$change['to']}");
                            }
                        }
                        break;
                    case 'no_change':
                        $this->line("  ✅ OK: {$message}");
                        break;
                }
            }
        }

        $this->line('');

        // Show site assignment results
        $this->info('📍 Site Assignment Results:');
        foreach ($results['site_assignment'] as $assignment) {
            $action = $assignment['action'];
            $message = $assignment['message'];
            
            switch ($action) {
                case 'assigned':
                    $this->line("  ✅ {$message}");
                    break;
                case 'no_match':
                    $this->line("  ⚠️  {$message}");
                    break;
            }
        }

        $this->line('');
        $this->info('🎉 Operator credential sync completed!');

        return 0;
    }
}
