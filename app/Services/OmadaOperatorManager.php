<?php

namespace App\Services;

use App\Models\ControllerSetting;
use App\Models\Operator;
use App\Models\OperatorCredential;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OmadaOperatorManager
{
    private OmadaService $omadaService;

    public function __construct(OmadaService $omadaService)
    {
        $this->omadaService = $omadaService;
    }

    /**
     * Create operator account in Omada controller
     */
    public function createOperatorAccount(Operator $operator): array
    {
        $settings = ControllerSetting::query()->first();
        if (!$settings) {
            throw new RuntimeException('Controller settings not found');
        }

        $credentials = $operator->credentials()->first();
        if (!$credentials) {
            throw new RuntimeException('Operator credentials not found in database');
        }

        try {
            // Authenticate with admin credentials
            $normalized = $this->getAdminSettings($settings);
            // Use the same pattern as OmadaService for OpenAPI authentication
            $client = $this->omadaService->client($normalized);
            $info = $this->omadaService->extractControllerInfo(
                $this->omadaService->request($client, 'get', '/api/info')
            )['omadac_id'];
            $openApi = $this->omadaService->request($client, 'post', '/openapi/v1/login', [
                'username' => $normalized['username'],
                'password' => $normalized['password'],
            ]);

            // Check if operator already exists
            if ($this->operatorExists($openApi, $credentials->hotspot_operator_username)) {
                return [
                    'action' => 'exists',
                    'message' => "Operator '{$credentials->hotspot_operator_username}' already exists in Omada controller",
                    'username' => $credentials->hotspot_operator_username,
                ];
            }

            // Create operator account
            $operatorData = [
                'name' => $operator->business_name,
                'username' => $credentials->hotspot_operator_username,
                'password' => $credentials->hotspot_operator_password,
                'role' => 'operator', // Adjust based on Omada API requirements
                'permission' => [
                    'sites' => $this->getOperatorSiteIds($operator),
                    'privileges' => ['hotspot_management'], // Adjust as needed
                ],
            ];

            $response = $this->createOmadaOperator($openApi, $operatorData);

            Log::info('Created Omada operator account', [
                'operator' => $operator->business_name,
                'username' => $credentials->hotspot_operator_username,
                'response' => $response,
            ]);

            return [
                'action' => 'created',
                'message' => "Successfully created operator '{$credentials->hotspot_operator_username}' in Omada controller",
                'username' => $credentials->hotspot_operator_username,
                'response' => $response,
            ];

        } catch (\Throwable $exception) {
            Log::error('Failed to create Omada operator account', [
                'operator' => $operator->business_name,
                'username' => $credentials->hotspot_operator_username,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException("Failed to create operator account: {$exception->getMessage()}");
        }
    }

    /**
     * Check if operator exists in Omada controller
     */
    public function operatorExists(array $openApi, string $username): bool
    {
        try {
            // Try to get operator by username
            $response = $this->omadaService->request(
                $openApi['client'],
                'get',
                "/openapi/v1/{$openApi['omadac_id']}/operators"
            );

            if (isset($response['data'])) {
                foreach ($response['data'] as $operator) {
                    if ($operator['username'] === $username) {
                        return true;
                    }
                }
            }

            return false;
        } catch (\Throwable $exception) {
            Log::warning('Failed to check operator existence', [
                'username' => $username,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Create operator in Omada controller
     */
    private function createOmadaOperator(array $openApi, array $operatorData): array
    {
        return $this->omadaService->request(
            $openApi['client'],
            'post',
            "/openapi/v1/{$openApi['omadac_id']}/operators",
            $operatorData
        );
    }

    /**
     * Update operator permissions in Omada controller
     */
    public function updateOperatorPermissions(Operator $operator): array
    {
        $settings = ControllerSetting::query()->first();
        if (!$settings) {
            throw new RuntimeException('Controller settings not found');
        }

        $credentials = $operator->credentials()->first();
        if (!$credentials) {
            throw new RuntimeException('Operator credentials not found in database');
        }

        try {
            $normalized = $this->getAdminSettings($settings);
            $client = $this->omadaService->client($normalized);
            $info = $this->omadaService->extractControllerInfo(
                $this->omadaService->request($client, 'get', '/api/info')
            )['omadac_id'];
            $openApi = $this->omadaService->request($client, 'post', '/openapi/v1/login', [
                'username' => $normalized['username'],
                'password' => $normalized['password'],
            ]);

            // Get operator ID
            $operatorId = $this->getOperatorId($openApi, $credentials->hotspot_operator_username);
            if (!$operatorId) {
                throw new RuntimeException("Operator '{$credentials->hotspot_operator_username}' not found in Omada controller");
            }

            // Update permissions
            $permissionData = [
                'sites' => $this->getOperatorSiteIds($operator),
                'privileges' => ['hotspot_management'],
            ];

            $response = $this->omadaService->request(
                $openApi['client'],
                'put',
                "/openapi/v1/{$openApi['omadac_id']}/operators/{$operatorId}/permissions",
                $permissionData
            );

            Log::info('Updated Omada operator permissions', [
                'operator' => $operator->business_name,
                'username' => $credentials->hotspot_operator_username,
                'sites' => $this->getOperatorSiteIds($operator),
            ]);

            return [
                'action' => 'updated',
                'message' => "Updated permissions for operator '{$credentials->hotspot_operator_username}'",
                'username' => $credentials->hotspot_operator_username,
                'sites' => $this->getOperatorSiteIds($operator),
                'response' => $response,
            ];

        } catch (\Throwable $exception) {
            Log::error('Failed to update Omada operator permissions', [
                'operator' => $operator->business_name,
                'username' => $credentials->hotspot_operator_username,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException("Failed to update operator permissions: {$exception->getMessage()}");
        }
    }

    /**
     * Delete operator from Omada controller
     */
    public function deleteOperatorAccount(Operator $operator): array
    {
        $settings = ControllerSetting::query()->first();
        if (!$settings) {
            throw new RuntimeException('Controller settings not found');
        }

        $credentials = $operator->credentials()->first();
        if (!$credentials) {
            throw new RuntimeException('Operator credentials not found in database');
        }

        try {
            $normalized = $this->getAdminSettings($settings);
            $client = $this->omadaService->client($normalized);
            $info = $this->omadaService->extractControllerInfo(
                $this->omadaService->request($client, 'get', '/api/info')
            )['omadac_id'];
            $openApi = $this->omadaService->request($client, 'post', '/openapi/v1/login', [
                'username' => $normalized['username'],
                'password' => $normalized['password'],
            ]);

            $operatorId = $this->getOperatorId($openApi, $credentials->hotspot_operator_username);
            if (!$operatorId) {
                return [
                    'action' => 'not_found',
                    'message' => "Operator '{$credentials->hotspot_operator_username}' not found in Omada controller",
                    'username' => $credentials->hotspot_operator_username,
                ];
            }

            $response = $this->omadaService->request(
                $openApi['client'],
                'delete',
                "/openapi/v1/{$openApi['omadac_id']}/operators/{$operatorId}"
            );

            Log::info('Deleted Omada operator account', [
                'operator' => $operator->business_name,
                'username' => $credentials->hotspot_operator_username,
            ]);

            return [
                'action' => 'deleted',
                'message' => "Deleted operator '{$credentials->hotspot_operator_username}' from Omada controller",
                'username' => $credentials->hotspot_operator_username,
                'response' => $response,
            ];

        } catch (\Throwable $exception) {
            Log::error('Failed to delete Omada operator account', [
                'operator' => $operator->business_name,
                'username' => $credentials->hotspot_operator_username,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException("Failed to delete operator account: {$exception->getMessage()}");
        }
    }

    /**
     * Get operator ID from Omada controller
     */
    private function getOperatorId(array $openApi, string $username): ?string
    {
        try {
            $response = $this->omadaService->request(
                $openApi['client'],
                'get',
                "/openapi/v1/{$openApi['omadac_id']}/operators"
            );

            if (isset($response['data'])) {
                foreach ($response['data'] as $operator) {
                    if ($operator['username'] === $username) {
                        return $operator['id'];
                    }
                }
            }

            return null;
        } catch (\Throwable $exception) {
            Log::error('Failed to get operator ID', [
                'username' => $username,
                'error' => $exception->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get site IDs for operator
     */
    private function getOperatorSiteIds(Operator $operator): array
    {
        return $operator->sites()
            ->whereNotNull('omada_site_id')
            ->pluck('omada_site_id')
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Get admin settings for Omada API
     */
    private function getAdminSettings(ControllerSetting $settings): array
    {
        return [
            'username' => $settings->username,
            'password' => $settings->password,
            'base_url' => $settings->base_url,
            'api_client_id' => $settings->api_client_id,
            'api_client_secret' => $settings->api_client_secret,
        ];
    }

    /**
     * Validate operator credentials against Omada controller
     */
    public function validateOperatorCredentials(Operator $operator): array
    {
        $settings = ControllerSetting::query()->first();
        if (!$settings) {
            throw new RuntimeException('Controller settings not found');
        }

        $credentials = $operator->credentials()->first();
        if (!$credentials) {
            throw new RuntimeException('Operator credentials not found in database');
        }

        try {
            // Try to authenticate with operator credentials
            $operatorSettings = [
                'username' => $credentials->hotspot_operator_username,
                'password' => $credentials->hotspot_operator_password,
                'base_url' => $settings->base_url,
                'api_client_id' => $settings->api_client_id,
                'api_client_secret' => $settings->api_client_secret,
            ];

            // Use the same pattern as OmadaService for OpenAPI authentication
            $client = $this->omadaService->client($operatorSettings);
            $info = $this->omadaService->extractControllerInfo(
                $this->omadaService->request($client, 'get', '/api/info')
            )['omadac_id'];
            $openApi = $this->omadaService->request($client, 'post', '/openapi/v1/login', [
                'username' => $operatorSettings['username'],
                'password' => $operatorSettings['password'],
            ]);

            return [
                'valid' => true,
                'message' => "Operator credentials are valid in Omada controller",
                'username' => $credentials->hotspot_operator_username,
            ];

        } catch (\Throwable $exception) {
            return [
                'valid' => false,
                'message' => "Operator credentials are invalid: {$exception->getMessage()}",
                'username' => $credentials->hotspot_operator_username,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
