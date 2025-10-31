<?php

namespace EriMeilis\CloudflareD1\Database;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use InvalidArgumentException;
use PDO;
use EriMeilis\CloudflareD1\Http\D1ApiClient;

class D1Connector extends Connector implements ConnectorInterface
{
    /**
     * Establish a database connection
     *
     * @param  array  $config  Connection configuration
     * @return D1Pdo
     */
    public function connect(array $config): PDO
    {
        // Validate required configuration
        $this->validateConfig($config);

        // Create D1 API client
        $apiClient = new D1ApiClient(
            $config['account_id'],
            $config['database_id'],
            $config['api_token']
        );

        // Create and return custom PDO instance
        return new D1Pdo($apiClient);
    }

    /**
     * Validate connection configuration
     *
     * @throws InvalidArgumentException
     */
    protected function validateConfig(array $config): void
    {
        $required = ['account_id', 'database_id', 'api_token'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new InvalidArgumentException(
                    "D1 connection configuration missing required key: {$key}"
                );
            }
        }
    }

    /**
     * Get the default PDO connection options
     */
    public function getDefaultOptions(): array
    {
        return [
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }
}
