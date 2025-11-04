<?php

namespace EriMeilis\CloudflareD1\Tests\Mock;

use EriMeilis\CloudflareD1\Http\D1ApiClient;
use PDO;

/**
 * Local D1 API Client for testing
 * Uses in-memory SQLite instead of HTTP calls to Cloudflare API.
 */
class LocalD1ApiClient extends D1ApiClient
{
    protected PDO $pdo;

    // Shared PDO instance across all LocalD1ApiClient instances in the same test
    protected static ?PDO $sharedPdo = null;

    public function __construct()
    {
        // Use shared in-memory SQLite database so data persists across queries
        if (self::$sharedPdo === null) {
            self::$sharedPdo = new PDO('sqlite::memory:');
            self::$sharedPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Enable foreign keys (critical for D1 compatibility)
            self::$sharedPdo->exec('PRAGMA foreign_keys = ON');
        }

        $this->pdo = self::$sharedPdo;
    }

    /**
     * Reset the shared database (for test isolation).
     */
    public static function resetDatabase(): void
    {
        self::$sharedPdo = null;
    }

    /**
     * Execute query locally using SQLite.
     */
    public function query(string $sql, array $bindings = []): array
    {
        return $this->executeLocally($sql, $bindings);
    }

    /**
     * Execute raw query locally.
     */
    public function raw(string $sql, array $bindings = []): array
    {
        return $this->executeLocally($sql, $bindings);
    }

    /**
     * Execute batch locally.
     */
    public function batch(array $statements): array
    {
        $results = [];

        foreach ($statements as $statement) {
            $sql = is_array($statement) ? $statement['sql'] : $statement;
            $params = is_array($statement) ? ($statement['params'] ?? []) : [];

            $results[] = $this->executeLocally($sql, $params);
        }

        return $results;
    }

    /**
     * Execute SQL against local SQLite.
     */
    protected function executeLocally(string $sql, array $bindings = []): array
    {
        // Don't catch PDOException - let it bubble up so Laravel can handle constraint violations
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        // Simulate D1 API response format
        if (stripos($sql, 'SELECT') === 0 || stripos($sql, 'PRAGMA') === 0) {
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                [
                    'success' => true,
                    'meta'    => [
                        'rows_read'    => count($results),
                        'rows_written' => 0,
                    ],
                    'results' => $results,
                ],
            ];
        }

        // For INSERT/UPDATE/DELETE
        return [
            [
                'success' => true,
                'meta'    => [
                    'rows_read'    => 0,
                    'rows_written' => $stmt->rowCount(),
                    'last_row_id'  => $this->pdo->lastInsertId(),
                ],
                'results' => [],
            ],
        ];
    }

    public function getAccountId(): string
    {
        return 'local-test-account';
    }

    public function getDatabaseId(): string
    {
        return 'local-test-database';
    }
}
