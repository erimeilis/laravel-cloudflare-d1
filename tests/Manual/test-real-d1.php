#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

use EriMeilis\CloudflareD1\Http\D1ApiClient;

/**
 * Real Cloudflare D1 Connection Test.
 *
 * This script tests the package with your actual Cloudflare D1 database.
 *
 * Usage:
 *   php test-real-d1.php <account_id> <database_id> <api_token>
 *
 * Or set environment variables:
 *   CLOUDFLARE_ACCOUNT_ID=xxx
 *   CLOUDFLARE_D1_DATABASE_ID=xxx
 *   CLOUDFLARE_D1_API_TOKEN=xxx
 *   php test-real-d1.php
 */
echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║          Laravel Cloudflare D1 - Real Connection Test          ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Get credentials from arguments or environment
$accountId = $argv[1] ?? getenv('CLOUDFLARE_ACCOUNT_ID');
$databaseId = $argv[2] ?? getenv('CLOUDFLARE_D1_DATABASE_ID');
$apiToken = $argv[3] ?? getenv('CLOUDFLARE_D1_API_TOKEN');

if (!$accountId || !$databaseId || !$apiToken) {
    echo "❌ Missing credentials!\n\n";
    echo "Usage:\n";
    echo "  php test-real-d1.php <account_id> <database_id> <api_token>\n\n";
    echo "Or set environment variables:\n";
    echo "  CLOUDFLARE_ACCOUNT_ID=xxx\n";
    echo "  CLOUDFLARE_D1_DATABASE_ID=xxx\n";
    echo "  CLOUDFLARE_D1_API_TOKEN=xxx\n";
    echo "  php test-real-d1.php\n\n";
    exit(1);
}

echo "📋 Configuration:\n";
echo '   Account ID: '.substr($accountId, 0, 8)."...\n";
echo '   Database ID: '.substr($databaseId, 0, 8)."...\n";
echo '   API Token: '.substr($apiToken, 0, 8)."...\n";
echo "\n";

try {
    echo "🔌 Creating D1 API Client...\n";
    $apiClient = new D1ApiClient($accountId, $databaseId, $apiToken);
    echo "✅ API Client created\n\n";

    // Test 1: Simple Query
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Test 1: Simple Query\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $start = microtime(true);
    $result = $apiClient->raw("SELECT 1 as test, 'Hello D1!' as message");
    $duration = (microtime(true) - $start) * 1000;

    echo "✅ Query executed successfully!\n";
    echo '⏱️  Duration: '.number_format($duration, 2)."ms\n";
    echo '📊 Result: '.json_encode($result, JSON_PRETTY_PRINT)."\n\n";

    // Test 2: Create Table
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Test 2: Create Table\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    // Drop table if exists
    try {
        $apiClient->raw('DROP TABLE IF EXISTS test_users');
        echo "🗑️  Dropped existing test_users table\n";
    } catch (Exception $e) {
        // Table might not exist
    }

    $start = microtime(true);
    $createTable = "
        CREATE TABLE test_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            created_at TEXT DEFAULT (datetime('now'))
        )
    ";
    $apiClient->raw($createTable);
    $duration = (microtime(true) - $start) * 1000;

    echo "✅ Table created successfully!\n";
    echo '⏱️  Duration: '.number_format($duration, 2)."ms\n\n";

    // Test 3: Insert Data (Sequential - Slow)
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Test 3: Sequential INSERTs (10 queries)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $start = microtime(true);
    for ($i = 1; $i <= 10; $i++) {
        $apiClient->raw(
            'INSERT INTO test_users (name, email) VALUES (?, ?)',
            ["User {$i}", "user{$i}@example.com"]
        );
    }
    $duration = (microtime(true) - $start) * 1000;

    echo "✅ 10 INSERTs completed\n";
    echo '⏱️  Duration: '.number_format($duration, 2)."ms\n";
    echo '📈 Average per query: '.number_format($duration / 10, 2)."ms\n\n";

    // Test 4: Batch Insert (Fast!)
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Test 4: Batched INSERTs (10 queries in 1 batch)\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $batchStatements = [];
    for ($i = 11; $i <= 20; $i++) {
        $batchStatements[] = [
            'sql'    => 'INSERT INTO test_users (name, email) VALUES (?, ?)',
            'params' => ["Batch User {$i}", "batch{$i}@example.com"],
        ];
    }

    $start = microtime(true);
    $apiClient->batch($batchStatements);
    $duration = (microtime(true) - $start) * 1000;

    echo "✅ Batch INSERT completed\n";
    echo '⏱️  Duration: '.number_format($duration, 2)."ms\n";
    echo '📈 Average per query: '.number_format($duration / 10, 2)."ms\n\n";

    // Test 5: Query Data
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Test 5: Query Data\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $start = microtime(true);
    $result = $apiClient->raw('SELECT COUNT(*) as count FROM test_users');
    $duration = (microtime(true) - $start) * 1000;

    $count = $result[0]['results']['rows'][0][0] ?? 0;

    echo "✅ Query executed\n";
    echo '⏱️  Duration: '.number_format($duration, 2)."ms\n";
    echo "📊 Total users: {$count}\n\n";

    // Test 6: Complex Query
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Test 6: SELECT with WHERE\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $start = microtime(true);
    $result = $apiClient->raw('SELECT * FROM test_users WHERE id <= ? ORDER BY id', [5]);
    $duration = (microtime(true) - $start) * 1000;

    echo "✅ Query executed\n";
    echo '⏱️  Duration: '.number_format($duration, 2)."ms\n";
    echo '📊 Users found: '.count($result[0]['results']['rows'])."\n";
    echo "📋 Sample data:\n";
    foreach (array_slice($result[0]['results']['rows'], 0, 3) as $row) {
        $columns = $result[0]['results']['columns'];
        $user = array_combine($columns, $row);
        echo "   • ID: {$user['id']}, Name: {$user['name']}, Email: {$user['email']}\n";
    }
    echo "\n";

    // Test 7: Update
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Test 7: UPDATE\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $start = microtime(true);
    $result = $apiClient->raw('UPDATE test_users SET name = ? WHERE id = ?', ['Updated User', 1]);
    $duration = (microtime(true) - $start) * 1000;

    echo "✅ Update executed\n";
    echo '⏱️  Duration: '.number_format($duration, 2)."ms\n";
    echo '📊 Rows affected: '.($result[0]['meta']['rows_written'] ?? 0)."\n\n";

    // Test 8: Delete
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Test 8: DELETE\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $start = microtime(true);
    $result = $apiClient->raw('DELETE FROM test_users WHERE id > ?', [15]);
    $duration = (microtime(true) - $start) * 1000;

    echo "✅ Delete executed\n";
    echo '⏱️  Duration: '.number_format($duration, 2)."ms\n";
    echo '📊 Rows deleted: '.($result[0]['meta']['rows_written'] ?? 0)."\n\n";

    // Cleanup
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Cleanup\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $apiClient->raw('DROP TABLE test_users');
    echo "🗑️  Test table dropped\n\n";

    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║                    ✅ All Tests Passed!                        ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
} catch (Exception $e) {
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║                      ❌ Test Failed!                           ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo 'Error: '.$e->getMessage()."\n";
    echo 'File: '.$e->getFile().':'.$e->getLine()."\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString()."\n";
    exit(1);
}
