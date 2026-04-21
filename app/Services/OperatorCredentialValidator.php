<?php

namespace App\Services;

use App\Models\Operator;
use App\Models\OperatorCredential;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class OperatorCredentialValidator
{
    /**
     * Generate expected username for an operator based on naming convention
     */
    public static function generateExpectedUsername(Operator $operator): string
    {
        return strtolower(str_replace([' ', '_'], '', $operator->business_name)) . '_operator';
    }

    /**
     * Validate if operator credentials follow naming convention
     */
    public static function validateCredentialNaming(Operator $operator): array
    {
        $issues = [];
        $credentials = $operator->credentials()->first();

        if (!$credentials) {
            $issues[] = [
                'type' => 'missing_credentials',
                'message' => "Operator {$operator->business_name} has no credentials configured",
                'severity' => 'error',
                'expected_username' => self::generateExpectedUsername($operator),
            ];
            return $issues;
        }

        $expectedUsername = self::generateExpectedUsername($operator);
        $actualUsername = $credentials->hotspot_operator_username;

        if ($actualUsername !== $expectedUsername) {
            $issues[] = [
                'type' => 'naming_mismatch',
                'message' => "Credential username '{$actualUsername}' does not follow naming convention",
                'severity' => 'warning',
                'expected_username' => $expectedUsername,
                'actual_username' => $actualUsername,
            ];
        }

        if (empty($credentials->hotspot_operator_password)) {
            $issues[] = [
                'type' => 'missing_password',
                'message' => "Operator credentials have no password configured",
                'severity' => 'error',
            ];
        }

        return $issues;
    }

    /**
     * Validate all operators in the system
     */
    public static function validateAllOperators(): array
    {
        $allIssues = [];
        $operators = Operator::with('credentials')->get();

        foreach ($operators as $operator) {
            $issues = self::validateCredentialNaming($operator);
            if (!empty($issues)) {
                $allIssues[$operator->business_name] = $issues;
            }
        }

        return $allIssues;
    }

    /**
     * Check if credentials are properly configured for a site
     */
    public static function validateSiteCredentials($site): array
    {
        $issues = [];

        if (!$site->operator) {
            $issues[] = [
                'type' => 'no_operator',
                'message' => "Site '{$site->name}' has no operator assigned",
                'severity' => 'error',
            ];
            return $issues;
        }

        $operatorIssues = self::validateCredentialNaming($site->operator);
        if (!empty($operatorIssues)) {
            $issues = array_merge($issues, $operatorIssues);
        }

        return $issues;
    }

    /**
     * Log validation results
     */
    public static function logValidationResults(array $validationResults): void
    {
        foreach ($validationResults as $operatorName => $issues) {
            foreach ($issues as $issue) {
                $level = $issue['severity'] === 'error' ? 'error' : 'warning';
                Log::{$level}("Operator credential validation issue", [
                    'operator' => $operatorName,
                    'issue_type' => $issue['type'],
                    'message' => $issue['message'],
                    'expected_username' => $issue['expected_username'] ?? null,
                    'actual_username' => $issue['actual_username'] ?? null,
                ]);
            }
        }
    }
}
