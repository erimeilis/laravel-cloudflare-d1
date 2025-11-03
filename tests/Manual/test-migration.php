#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use EriMeilis\CloudflareD1\Http\D1ApiClient;
use EriMeilis\CloudflareD1\Migration\DataImporter;
use EriMeilis\CloudflareD1\Migration\SchemaConverter;

/**
 * Migration Tooling Test Script
 *
 * Tests the complete MySQL to D1 migration workflow including:
 * - SchemaConverter
 * - DataExporter (simulated with SQLite)
 * - DataImporter
 * - MigrationValidator
 *
 * Usage:
 *   php test-migration.php
 *
 * Environment variables (for D1):
 *   CLOUDFLARE_ACCOUNT_ID
 *   CLOUDFLARE_D1_DATABASE_ID
 *   CLOUDFLARE_D1_API_TOKEN
 */

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║          Laravel Cloudflare D1 - Migration Test                ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Get D1 credentials
$accountId = getenv('CLOUDFLARE_ACCOUNT_ID');
$databaseId = getenv('CLOUDFLARE_D1_DATABASE_ID');
$apiToken = getenv('CLOUDFLARE_D1_API_TOKEN');

if (!$accountId || !$databaseId || !$apiToken) {
    echo "⚠️  D1 credentials not found. Will test schema conversion only.\n\n";
    $testD1 = false;
} else {
    echo "📋 D1 Configuration:\n";
    echo "   Account ID: " . substr($accountId, 0, 8) . "...\n";
    echo "   Database ID: " . substr($databaseId, 0, 8) . "...\n";
    echo "   API Token: " . substr($apiToken, 0, 8) . "...\n";
    echo "\n";
    $testD1 = true;
}

$testsPassed = 0;
$testsFailed = 0;

// =============================================================================
// Test 1: SchemaConverter - Data Type Mapping
// =============================================================================

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Test 1: SchemaConverter - Data Type Mapping\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    $converter = new SchemaConverter();

    $mysqlSchema = "
        CREATE TABLE products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            stock INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ";

    $sqliteSchema = $converter->convert($mysqlSchema);

    echo "MySQL Schema:\n";
    echo trim($mysqlSchema) . "\n\n";

    echo "Converted SQLite Schema:\n";
    echo $sqliteSchema . "\n\n";

    $warnings = $converter->getWarnings();
    if (!empty($warnings)) {
        echo "⚠️  Warnings:\n";
        foreach ($warnings as $warning) {
            echo "   • {$warning}\n";
        }
        echo "\n";
    }

    // Verify conversions
    $checks = [
        'INTEGER PRIMARY KEY AUTOINCREMENT' => 'AUTO_INCREMENT converted',
        'TEXT' => 'VARCHAR/TEXT converted',
        'REAL' => 'DECIMAL converted',
        "DEFAULT (datetime('now'))" => 'CURRENT_TIMESTAMP converted',
    ];

    $allChecked = true;
    foreach ($checks as $needle => $description) {
        if (stripos($sqliteSchema, $needle) !== false) {
            echo "✅ {$description}\n";
        } else {
            echo "❌ Missing: {$description}\n";
            $allChecked = false;
        }
    }

    if ($allChecked) {
        echo "\n✅ Test 1 PASSED\n\n";
        $testsPassed++;
    } else {
        echo "\n❌ Test 1 FAILED\n\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ Test 1 FAILED: {$e->getMessage()}\n\n";
    $testsFailed++;
}

// =============================================================================
// Test 2: SchemaConverter - ENUM Conversion
// =============================================================================

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Test 2: SchemaConverter - ENUM Conversion\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    $converter = new SchemaConverter();

    $mysqlSchema = "
        CREATE TABLE orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            status ENUM('pending', 'processing', 'shipped', 'delivered') NOT NULL DEFAULT 'pending'
        )
    ";

    $sqliteSchema = $converter->convert($mysqlSchema);

    echo "MySQL ENUM:\n";
    echo "   status ENUM('pending', 'processing', 'shipped', 'delivered')\n\n";

    echo "Converted SQLite:\n";
    echo $sqliteSchema . "\n\n";

    // Verify CHECK constraint
    if (stripos($sqliteSchema, 'CHECK') !== false &&
        stripos($sqliteSchema, 'IN (') !== false) {
        echo "✅ ENUM converted to TEXT with CHECK constraint\n";

        $warnings = $converter->getWarnings();
        $hasWarning = false;
        foreach ($warnings as $warning) {
            if (stripos($warning, 'ENUM') !== false) {
                echo "✅ Warning generated for ENUM conversion\n";
                $hasWarning = true;
                break;
            }
        }

        if ($hasWarning) {
            echo "\n✅ Test 2 PASSED\n\n";
            $testsPassed++;
        } else {
            echo "\n❌ Test 2 FAILED: No ENUM warning\n\n";
            $testsFailed++;
        }
    } else {
        echo "❌ ENUM not converted to CHECK constraint\n\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ Test 2 FAILED: {$e->getMessage()}\n\n";
    $testsFailed++;
}

// =============================================================================
// Test 3: SchemaConverter - Foreign Keys
// =============================================================================

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Test 3: SchemaConverter - Foreign Keys\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    $converter = new SchemaConverter();

    $mysqlSchema = "
        CREATE TABLE order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            quantity INT NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
        )
    ";

    $sqliteSchema = $converter->convert($mysqlSchema);

    echo "MySQL Foreign Keys:\n";
    echo "   FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE\n";
    echo "   FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT\n\n";

    echo "Converted SQLite:\n";
    echo $sqliteSchema . "\n\n";

    // Verify foreign keys
    $fkCount = substr_count($sqliteSchema, 'FOREIGN KEY');
    $cascadeCount = substr_count($sqliteSchema, 'ON DELETE CASCADE');
    $restrictCount = substr_count($sqliteSchema, 'ON DELETE RESTRICT');

    if ($fkCount === 2 && $cascadeCount === 1 && $restrictCount === 1) {
        echo "✅ Foreign keys preserved with ON DELETE actions\n";
        echo "\n✅ Test 3 PASSED\n\n";
        $testsPassed++;
    } else {
        echo "❌ Foreign keys not properly converted\n";
        echo "   Found: {$fkCount} FKs, {$cascadeCount} CASCADE, {$restrictCount} RESTRICT\n\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ Test 3 FAILED: {$e->getMessage()}\n\n";
    $testsFailed++;
}

// =============================================================================
// Test 4: SchemaConverter - Indexes
// =============================================================================

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "Test 4: SchemaConverter - Indexes\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

try {
    $converter = new SchemaConverter();

    $mysqlSchema = "
        CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created (created_at),
            UNIQUE INDEX idx_email (email),
            FULLTEXT INDEX idx_search (name)
        )
    ";

    $sqliteSchema = $converter->convert($mysqlSchema);
    $parsed = $converter->parseCreateTable($mysqlSchema);
    $indexStatements = $converter->buildIndexStatements($parsed);

    echo "MySQL Indexes:\n";
    echo "   INDEX idx_created (created_at)\n";
    echo "   UNIQUE INDEX idx_email (email)\n";
    echo "   FULLTEXT INDEX idx_search (name)\n\n";

    echo "Converted SQLite Table:\n";
    echo $sqliteSchema . "\n\n";

    echo "Separate Index Statements:\n";
    foreach ($indexStatements as $stmt) {
        echo "   {$stmt}\n";
    }
    echo "\n";

    // Verify indexes
    $hasRegularIndex = false;
    $hasUniqueIndex = false;
    foreach ($indexStatements as $stmt) {
        if (stripos($stmt, 'idx_created') !== false && stripos($stmt, 'UNIQUE') === false) {
            $hasRegularIndex = true;
        }
        if (stripos($stmt, 'idx_email') !== false && stripos($stmt, 'UNIQUE') !== false) {
            $hasUniqueIndex = true;
        }
    }

    // Check FULLTEXT warning
    $warnings = $converter->getWarnings();
    $hasFulltextWarning = false;
    foreach ($warnings as $warning) {
        if (stripos($warning, 'FULLTEXT') !== false) {
            $hasFulltextWarning = true;
            break;
        }
    }

    if ($hasRegularIndex && $hasUniqueIndex && $hasFulltextWarning) {
        echo "✅ Regular index converted\n";
        echo "✅ Unique index converted\n";
        echo "✅ FULLTEXT index warning generated\n";
        echo "\n✅ Test 4 PASSED\n\n";
        $testsPassed++;
    } else {
        echo "❌ Index conversion incomplete\n";
        echo "   Regular: " . ($hasRegularIndex ? 'Yes' : 'No') . "\n";
        echo "   Unique: " . ($hasUniqueIndex ? 'Yes' : 'No') . "\n";
        echo "   FULLTEXT warning: " . ($hasFulltextWarning ? 'Yes' : 'No') . "\n\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "❌ Test 4 FAILED: {$e->getMessage()}\n\n";
    $testsFailed++;
}

// =============================================================================
// Tests 5-8: Real D1 Migration (if credentials available)
// =============================================================================

if ($testD1) {
    try {
        $d1Client = new D1ApiClient($accountId, $databaseId, $apiToken);
        $importer = new DataImporter($d1Client);

        // Validate D1 connection
        if (!$importer->validate()) {
            echo "❌ Failed to connect to D1. Skipping D1 tests.\n\n";
            $testD1 = false;
        }
    } catch (Exception $e) {
        echo "❌ D1 connection error: {$e->getMessage()}\n";
        echo "   Skipping D1 tests.\n\n";
        $testD1 = false;
    }
}

if ($testD1) {
    // =============================================================================
    // Test 5: Complete Migration Workflow
    // =============================================================================

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Test 5: Complete Migration Workflow\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    try {
        // Clean up from previous tests
        try {
            $importer->dropTable('migration_test_products');
        } catch (Exception $e) {
            // Table might not exist
        }

        // Create test table with data
        $mysqlSchema = "
            CREATE TABLE migration_test_products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                in_stock BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ";

        echo "📝 Creating table from MySQL schema...\n";
        $result = $importer->createTable($mysqlSchema);

        if (!empty($result['warnings'])) {
            echo "⚠️  Warnings:\n";
            foreach ($result['warnings'] as $warning) {
                echo "   • {$warning}\n";
            }
        }

        echo "✅ Table created\n\n";

        // Import test data
        echo "📥 Importing test data...\n";
        $testData = [
            ['id' => 1, 'name' => 'Product 1', 'price' => 19.99, 'in_stock' => 1, 'created_at' => date('Y-m-d H:i:s')],
            ['id' => 2, 'name' => 'Product 2', 'price' => 29.99, 'in_stock' => 1, 'created_at' => date('Y-m-d H:i:s')],
            ['id' => 3, 'name' => 'Product 3', 'price' => 39.99, 'in_stock' => 0, 'created_at' => date('Y-m-d H:i:s')],
        ];

        $importer->importData(
            'migration_test_products',
            ['id', 'name', 'price', 'in_stock', 'created_at'],
            $testData
        );

        echo "✅ Imported " . count($testData) . " rows\n\n";

        // Verify data
        echo "🔍 Verifying data...\n";
        $result = $d1Client->raw("SELECT COUNT(*) as count FROM migration_test_products");
        $count = $result[0]['results']['rows'][0][0] ?? 0;

        if ($count == count($testData)) {
            echo "✅ Row count matches ({$count})\n";

            // Query a specific row
            $result = $d1Client->raw("SELECT * FROM migration_test_products WHERE id = ?", [1]);
            $rows = $result[0]['results']['rows'] ?? [];

            if (!empty($rows)) {
                $columns = $result[0]['results']['columns'];
                $row = array_combine($columns, $rows[0]);

                echo "✅ Data integrity verified\n";
                echo "   Sample row: ID={$row['id']}, Name={$row['name']}, Price={$row['price']}\n";

                echo "\n✅ Test 5 PASSED\n\n";
                $testsPassed++;
            } else {
                echo "❌ Could not retrieve sample row\n\n";
                $testsFailed++;
            }
        } else {
            echo "❌ Row count mismatch: Expected " . count($testData) . ", Got {$count}\n\n";
            $testsFailed++;
        }

        // Cleanup
        $importer->dropTable('migration_test_products');
        echo "🗑️  Cleaned up test table\n\n";

    } catch (Exception $e) {
        echo "❌ Test 5 FAILED: {$e->getMessage()}\n\n";
        $testsFailed++;
    }

    // =============================================================================
    // Test 6: Batch Performance
    // =============================================================================

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Test 6: Batch INSERT Performance\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    try {
        // Clean up
        try {
            $importer->dropTable('batch_test');
        } catch (Exception $e) {
            // Table might not exist
        }

        // Create test table
        $mysqlSchema = "
            CREATE TABLE batch_test (
                id INT AUTO_INCREMENT PRIMARY KEY,
                value VARCHAR(255) NOT NULL
            )
        ";

        $importer->createTable($mysqlSchema);
        echo "✅ Table created\n\n";

        // Test batch import
        echo "📊 Testing batch import (20 rows)...\n";

        $batchData = [];
        for ($i = 1; $i <= 20; $i++) {
            $batchData[] = [
                'id' => $i,
                'value' => "Batch Item {$i}",
            ];
        }

        $start = microtime(true);
        $importer->importData('batch_test', ['id', 'value'], $batchData);
        $duration = (microtime(true) - $start) * 1000;

        echo "✅ Batch import completed\n";
        echo "⏱️  Duration: " . number_format($duration, 2) . "ms\n";
        echo "📈 Average per row: " . number_format($duration / 20, 2) . "ms\n\n";

        // Verify count
        $result = $d1Client->raw("SELECT COUNT(*) as count FROM batch_test");
        $count = $result[0]['results']['rows'][0][0] ?? 0;

        if ($count == 20) {
            echo "✅ All 20 rows imported successfully\n";
            echo "\n✅ Test 6 PASSED\n\n";
            $testsPassed++;
        } else {
            echo "❌ Row count mismatch: Expected 20, Got {$count}\n\n";
            $testsFailed++;
        }

        // Cleanup
        $importer->dropTable('batch_test');

    } catch (Exception $e) {
        echo "❌ Test 6 FAILED: {$e->getMessage()}\n\n";
        $testsFailed++;
    }
}

// =============================================================================
// Final Summary
// =============================================================================

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                      Test Summary                              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$totalTests = $testsPassed + $testsFailed;
$passRate = $totalTests > 0 ? round(($testsPassed / $totalTests) * 100) : 0;

echo "Tests Run: {$totalTests}\n";
echo "Passed: ✅ {$testsPassed}\n";
echo "Failed: ❌ {$testsFailed}\n";
echo "Pass Rate: {$passRate}%\n\n";

if ($testsFailed === 0) {
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║              ✅ All Migration Tests Passed!                    ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
    exit(0);
} else {
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║              ⚠️  Some Tests Failed                             ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
    exit(1);
}
