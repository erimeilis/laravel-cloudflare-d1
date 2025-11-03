<?php

namespace EriMeilis\CloudflareD1\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use EriMeilis\CloudflareD1\D1ServiceProvider;
use EriMeilis\CloudflareD1\Database\D1Connector;
use EriMeilis\CloudflareD1\Database\D1Pdo;
use EriMeilis\CloudflareD1\Tests\Mock\LocalD1ApiClient;
use Illuminate\Database\Connection;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Manually register d1 driver with local SQLite instead of using the service provider
        $this->app['db']->extend('d1', function ($config, $name) {
            // Use local SQLite for testing
            $apiClient = new LocalD1ApiClient();
            $pdo = new D1Pdo($apiClient);

            $connection = new \EriMeilis\CloudflareD1\Database\D1Connection(
                $pdo,
                $config['database'] ?? $name,
                $config['prefix'] ?? '',
                $config
            );

            // Enable foreign keys
            $connection->enableForeignKeyConstraints();

            return $connection;
        });
    }

    /**
     * Get package providers - Don't use the real provider, we register manually
     */
    protected function getPackageProviders($app): array
    {
        return [];
    }

    /**
     * Define environment setup
     */
    protected function defineEnvironment($app): void
    {
        // Use local D1 for testing
        $app['config']->set('database.default', 'd1');
        $app['config']->set('database.connections.d1', [
            'driver' => 'd1',
            'account_id' => 'test-account',
            'database_id' => 'test-db-id',
            'api_token' => 'test-token',
            'prefix' => '',
            'batch_enabled' => true,
            'batch_size' => 10,
            'use_raw_endpoint' => true,
        ]);
    }

    /**
     * Clean up database after tests
     */
    protected function tearDown(): void
    {
        // Reset the shared database for test isolation
        LocalD1ApiClient::resetDatabase();

        parent::tearDown();
    }
}
