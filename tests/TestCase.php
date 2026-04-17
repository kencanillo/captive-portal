<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->forceInMemorySqlite();

        parent::setUp();

        $defaultConnection = config('database.default');
        $databaseName = config('database.connections.sqlite.database');

        if ($defaultConnection !== 'sqlite' || $databaseName !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Unsafe test database configuration detected. Tests must run on sqlite :memory:, got connection [%s] database [%s].',
                (string) $defaultConnection,
                (string) $databaseName
            ));
        }
    }

    private function forceInMemorySqlite(): void
    {
        $projectRoot = dirname(__DIR__);

        $overrides = [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $projectRoot . '/bootstrap/cache/testing-config.php',
            'APP_EVENTS_CACHE' => $projectRoot . '/bootstrap/cache/testing-events.php',
            'APP_PACKAGES_CACHE' => $projectRoot . '/bootstrap/cache/testing-packages.php',
            'APP_ROUTES_CACHE' => $projectRoot . '/bootstrap/cache/testing-routes.php',
            'APP_SERVICES_CACHE' => $projectRoot . '/bootstrap/cache/testing-services.php',
            'DB_URL' => '',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_HOST' => '',
            'DB_PORT' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
            'DB_FOREIGN_KEYS' => 'true',
        ];

        foreach ($overrides as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
