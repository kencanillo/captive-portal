<?php

namespace App\Services;

use App\Models\Operator;
use App\Models\OperatorCredential;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OperatorCredentialSyncService
{
    /**
     * Sync operator credentials with naming convention
     */
    public static function syncOperatorCredentials(Operator $operator, bool $force = false, bool $syncToOmada = true): array
    {
        $results = [];
        $expectedUsername = OperatorCredentialValidator::generateExpectedUsername($operator);
        $existingCredentials = $operator->credentials()->first();

        if (!$existingCredentials) {
            // Create new credentials following naming convention
            $newCredentials = $operator->credentials()->create([
                'hotspot_operator_username' => $expectedUsername,
                'hotspot_operator_password' => self::generateSecurePassword($operator),
                'notes' => 'Auto-generated credentials following naming convention',
            ]);

            $results[] = [
                'action' => 'created',
                'message' => "Created credentials for {$operator->business_name}",
                'username' => $expectedUsername,
                'operator_id' => $operator->id,
            ];

            Log::info('Auto-created operator credentials', [
                'operator' => $operator->business_name,
                'username' => $expectedUsername,
                'operator_id' => $operator->id,
            ]);

            // Sync to Omada controller if requested
            if ($syncToOmada) {
                try {
                    $omadaManager = app(\App\Services\OmadaOperatorManager::class);
                    $omadaResult = $omadaManager->createOperatorAccount($operator);
                    $results[] = [
                        'action' => 'omada_sync',
                        'message' => $omadaResult['message'],
                        'username' => $omadaResult['username'],
                    ];
                } catch (\Throwable $exception) {
                    $results[] = [
                        'action' => 'omada_failed',
                        'message' => "Failed to sync to Omada: {$exception->getMessage()}",
                        'username' => $expectedUsername,
                    ];
                }
            }

            return $results;
        }

        // Check if credentials need updating
        $needsUpdate = false;
        $changes = [];

        if ($existingCredentials->hotspot_operator_username !== $expectedUsername) {
            $changes['username'] = [
                'from' => $existingCredentials->hotspot_operator_username,
                'to' => $expectedUsername,
            ];
            $needsUpdate = true;
        }

        if (empty($existingCredentials->hotspot_operator_password)) {
            $needsUpdate = true;
        }

        if ($needsUpdate || $force) {
            $password = empty($existingCredentials->hotspot_operator_password) 
                ? self::generateSecurePassword($operator) 
                : $existingCredentials->hotspot_operator_password;
                
            $existingCredentials->update([
                'hotspot_operator_username' => $expectedUsername,
                'hotspot_operator_password' => $password,
                'notes' => 'Auto-synced credentials to follow naming convention',
            ]);

            $results[] = [
                'action' => 'updated',
                'message' => "Updated credentials for {$operator->business_name}",
                'changes' => $changes,
                'username' => $expectedUsername,
                'operator_id' => $operator->id,
            ];

            Log::info('Auto-updated operator credentials', [
                'operator' => $operator->business_name,
                'changes' => $changes,
                'username' => $expectedUsername,
            ]);
        } else {
            $results[] = [
                'action' => 'no_change',
                'message' => "Credentials for {$operator->business_name} are already correct",
                'username' => $expectedUsername,
                'operator_id' => $operator->id,
            ];
        }

        return $results;
    }

    /**
     * Sync all operators' credentials
     */
    public static function syncAllOperators(bool $force = false, bool $syncToOmada = true): array
    {
        $allResults = [];
        $operators = Operator::with('credentials')->get();

        foreach ($operators as $operator) {
            $results = self::syncOperatorCredentials($operator, $force, $syncToOmada);
            $allResults[$operator->business_name] = $results;
        }

        return $allResults;
    }

    /**
     * Assign operators to unassigned sites
     */
    public static function assignOperatorsToSites(): array
    {
        $results = [];
        $unassignedSites = Site::whereNull('operator_id')->get();

        foreach ($unassignedSites as $site) {
            // Try to match site to operator by name pattern
            $matchedOperator = self::findMatchingOperator($site);

            if ($matchedOperator) {
                $site->update(['operator_id' => $matchedOperator->id]);
                $results[] = [
                    'action' => 'assigned',
                    'site' => $site->name,
                    'operator' => $matchedOperator->business_name,
                    'message' => "Assigned {$matchedOperator->business_name} to {$site->name}",
                ];

                Log::info('Auto-assigned operator to site', [
                    'site' => $site->name,
                    'operator' => $matchedOperator->business_name,
                ]);
            } else {
                $results[] = [
                    'action' => 'no_match',
                    'site' => $site->name,
                    'message' => "No matching operator found for {$site->name}",
                ];
            }
        }

        return $results;
    }

    /**
     * Find operator that matches site naming pattern
     */
    private static function findMatchingOperator(Site $site): ?Operator
    {
        $siteName = strtolower($site->name);
        
        // Try exact match first
        $operator = Operator::where('business_name', $site->name)->first();
        if ($operator) {
            return $operator;
        }

        // Try partial match
        $operators = Operator::all();
        foreach ($operators as $operator) {
            $operatorName = strtolower($operator->business_name);
            if (strpos($siteName, $operatorName) !== false || strpos($operatorName, $siteName) !== false) {
                return $operator;
            }
        }

        return null;
    }

    /**
     * Generate secure password for operator
     */
    private static function generateSecurePassword(Operator $operator): string
    {
        // Generate password based on operator name and current year
        $baseName = strtolower(str_replace([' ', '_'], '', $operator->business_name));
        return $baseName . '@' . date('Y') . 'Lab';
    }

    /**
     * Validate and sync all credentials in one operation
     */
    public static function validateAndSyncAll(bool $syncToOmada = true): array
    {
        $results = [
            'validation' => [],
            'sync' => [],
            'site_assignment' => [],
            'omada_sync' => [],
        ];

        // Step 1: Validate all operators
        $validationResults = OperatorCredentialValidator::validateAllOperators();
        $results['validation'] = $validationResults;
        OperatorCredentialValidator::logValidationResults($validationResults);

        // Step 2: Sync all operator credentials
        $syncResults = self::syncAllOperators(false, $syncToOmada);
        $results['sync'] = $syncResults;

        // Step 3: Assign operators to unassigned sites
        $assignmentResults = self::assignOperatorsToSites();
        $results['site_assignment'] = $assignmentResults;

        // Step 4: Collect Omada sync results
        if ($syncToOmada) {
            foreach ($syncResults as $operatorName => $operatorResults) {
                foreach ($operatorResults as $result) {
                    if (in_array($result['action'], ['omada_sync', 'omada_failed'])) {
                        $results['omada_sync'][$operatorName] = $result;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Prevent manual credential override by validating before save
     */
    public static function validateBeforeUpdate(Operator $operator, array $credentials): array
    {
        $errors = [];
        $expectedUsername = OperatorCredentialValidator::generateExpectedUsername($operator);

        if (isset($credentials['hotspot_operator_username'])) {
            // Only validate if it's empty or contains invalid characters
            if (empty($credentials['hotspot_operator_username'])) {
                $errors['hotspot_operator_username'] = [
                    'Username cannot be empty',
                ];
            } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $credentials['hotspot_operator_username'])) {
                $errors['hotspot_operator_username'] = [
                    'Username can only contain letters, numbers, underscores, and hyphens',
                ];
            }
            // Note: Removed strict naming convention validation to allow manual flexibility
        }

        if (isset($credentials['hotspot_operator_password']) && empty($credentials['hotspot_operator_password'])) {
            $errors['hotspot_operator_password'] = [
                'Password cannot be empty',
            ];
        }

        return $errors;
    }
}
