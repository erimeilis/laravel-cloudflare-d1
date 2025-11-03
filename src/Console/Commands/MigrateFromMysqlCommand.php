<?php

namespace EriMeilis\CloudflareD1\Console\Commands;

use EriMeilis\CloudflareD1\Http\D1ApiClient;
use EriMeilis\CloudflareD1\Migration\DataExporter;
use EriMeilis\CloudflareD1\Migration\DataImporter;
use EriMeilis\CloudflareD1\Migration\SchemaConverter;
use Illuminate\Console\Command;

/**
 * Migrate MySQL database to Cloudflare D1
 *
 * Usage:
 *   php artisan d1:migrate-from-mysql
 *   php artisan d1:migrate-from-mysql --tables=users,posts
 *   php artisan d1:migrate-from-mysql --structure-only
 *   php artisan d1:migrate-from-mysql --data-only
 *   php artisan d1:migrate-from-mysql --dry-run
 */
class MigrateFromMysqlCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'd1:migrate-from-mysql
                            {--host= : MySQL host (default: from config)}
                            {--port= : MySQL port (default: 3306)}
                            {--database= : MySQL database name (default: from config)}
                            {--username= : MySQL username (default: from config)}
                            {--password= : MySQL password (default: from config)}
                            {--tables= : Comma-separated list of tables to migrate (default: all)}
                            {--exclude= : Comma-separated list of tables to exclude}
                            {--structure-only : Only migrate table structure, no data}
                            {--data-only : Only migrate data, assume tables exist}
                            {--chunk-size=1000 : Number of rows to process per chunk}
                            {--dry-run : Show what would be migrated without executing}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate MySQL database to Cloudflare D1';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('╔════════════════════════════════════════════════════════════════╗');
        $this->info('║          MySQL to Cloudflare D1 Migration Tool                ║');
        $this->info('╚════════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Get MySQL connection parameters
        $mysqlConfig = $this->getMysqlConfig();

        // Get D1 connection
        $d1Client = $this->getD1Client();

        // Validate connections
        if (! $this->validateConnections($mysqlConfig, $d1Client)) {
            return self::FAILURE;
        }

        // Create migration components
        $exporter = new DataExporter(
            $mysqlConfig['host'],
            $mysqlConfig['database'],
            $mysqlConfig['username'],
            $mysqlConfig['password'],
            $mysqlConfig['port']
        );

        $exporter->setChunkSize((int) $this->option('chunk-size'));

        $importer = new DataImporter($d1Client, new SchemaConverter);

        // Get tables to migrate
        $tables = $this->getTablesToMigrate($exporter);

        if (empty($tables)) {
            $this->error('No tables to migrate!');

            return self::FAILURE;
        }

        // Show migration plan
        $this->showMigrationPlan($exporter, $tables);

        // Confirm before proceeding
        if (! $this->option('force') && ! $this->option('dry-run')) {
            if (! $this->confirm('Proceed with migration?')) {
                $this->info('Migration cancelled.');

                return self::SUCCESS;
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete. No changes made.');

            return self::SUCCESS;
        }

        // Execute migration
        return $this->executeMigration($exporter, $importer, $tables);
    }

    /**
     * Get MySQL configuration
     */
    protected function getMysqlConfig(): array
    {
        $defaultConnection = config('database.default');
        $defaultConfig = config("database.connections.{$defaultConnection}");

        return [
            'host' => $this->option('host') ?? $defaultConfig['host'] ?? 'localhost',
            'port' => (int) ($this->option('port') ?? $defaultConfig['port'] ?? 3306),
            'database' => $this->option('database') ?? $defaultConfig['database'],
            'username' => $this->option('username') ?? $defaultConfig['username'],
            'password' => $this->option('password') ?? $defaultConfig['password'] ?? '',
        ];
    }

    /**
     * Get D1 API client
     */
    protected function getD1Client(): D1ApiClient
    {
        return new D1ApiClient(
            config('database.connections.d1.account_id'),
            config('database.connections.d1.database_id'),
            config('database.connections.d1.api_token')
        );
    }

    /**
     * Validate both MySQL and D1 connections
     */
    protected function validateConnections(array $mysqlConfig, D1ApiClient $d1Client): bool
    {
        $this->info('🔍 Validating connections...');

        // Validate MySQL
        try {
            $exporter = new DataExporter(
                $mysqlConfig['host'],
                $mysqlConfig['database'],
                $mysqlConfig['username'],
                $mysqlConfig['password'],
                $mysqlConfig['port']
            );

            if (! $exporter->validate()) {
                $this->error('❌ Failed to connect to MySQL database');

                return false;
            }

            $this->info('✅ MySQL connection successful');
            $exporter->close();
        } catch (\Exception $e) {
            $this->error("❌ MySQL connection failed: {$e->getMessage()}");

            return false;
        }

        // Validate D1
        try {
            $importer = new DataImporter($d1Client);

            if (! $importer->validate()) {
                $this->error('❌ Failed to connect to Cloudflare D1');

                return false;
            }

            $this->info('✅ D1 connection successful');
        } catch (\Exception $e) {
            $this->error("❌ D1 connection failed: {$e->getMessage()}");

            return false;
        }

        $this->newLine();

        return true;
    }

    /**
     * Get list of tables to migrate
     */
    protected function getTablesToMigrate(DataExporter $exporter): array
    {
        $allTables = $exporter->getTables();

        // Filter by --tables option
        if ($this->option('tables')) {
            $requestedTables = explode(',', $this->option('tables'));
            $tables = array_intersect($allTables, $requestedTables);

            if (count($tables) !== count($requestedTables)) {
                $missing = array_diff($requestedTables, $tables);
                $this->warn('Warning: Tables not found: '.implode(', ', $missing));
            }
        } else {
            $tables = $allTables;
        }

        // Exclude tables
        if ($this->option('exclude')) {
            $excludeTables = explode(',', $this->option('exclude'));
            $tables = array_diff($tables, $excludeTables);
        }

        return array_values($tables);
    }

    /**
     * Show migration plan
     */
    protected function showMigrationPlan(DataExporter $exporter, array $tables): void
    {
        $this->info('📋 Migration Plan:');
        $this->newLine();

        $totalRows = 0;
        $tableData = [];

        foreach ($tables as $table) {
            $rowCount = $exporter->getTableCount($table);
            $totalRows += $rowCount;

            $tableData[] = [
                'table' => $table,
                'rows' => number_format($rowCount),
            ];
        }

        $this->table(['Table', 'Rows'], $tableData);

        $this->info("Total tables: ".count($tables));
        $this->info("Total rows: ".number_format($totalRows));

        if ($this->option('structure-only')) {
            $this->warn('Mode: Structure only (no data)');
        } elseif ($this->option('data-only')) {
            $this->warn('Mode: Data only (no structure)');
        }

        $this->newLine();
    }

    /**
     * Execute the migration
     */
    protected function executeMigration(DataExporter $exporter, DataImporter $importer, array $tables): int
    {
        $this->info('🚀 Starting migration...');
        $this->newLine();

        $structureOnly = $this->option('structure-only');
        $dataOnly = $this->option('data-only');

        $importer->resetStats();

        // Set up progress callbacks
        $exporter->onProgress(function ($table, $exported, $total) {
            $percentage = $total > 0 ? round(($exported / $total) * 100) : 100;
            $this->info("  📊 Exporting {$table}: {$exported}/{$total} rows ({$percentage}%)");
        });

        $importer->onProgress(function ($operation, $detail, $current, $total) {
            if ($operation === 'import_data') {
                $percentage = $total > 0 ? round(($current / $total) * 100) : 100;
                $this->info("  📥 Importing {$detail}: {$current}/{$total} rows ({$percentage}%)");
            } elseif ($operation === 'create_table') {
                $this->info("  ✅ Created table: {$detail}");
            }
        });

        try {
            foreach ($tables as $table) {
                $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
                $this->info("Migrating table: {$table}");
                $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

                // Export table structure
                $structure = $exporter->getTableStructure($table);
                $columns = array_column($exporter->getTableColumns($table), 'Field');
                $rowCount = $exporter->getTableCount($table);

                // Create table (unless data-only mode)
                if (! $dataOnly) {
                    $result = $importer->createTable($structure);

                    // Show warnings
                    if (! empty($result['warnings'])) {
                        foreach ($result['warnings'] as $warning) {
                            $this->warn("  ⚠️  {$warning}");
                        }
                    }
                }

                // Import data (unless structure-only mode)
                if (! $structureOnly && $rowCount > 0) {
                    $this->info("  📤 Exporting data from MySQL...");

                    // Use generator for memory efficiency
                    $dataGenerator = $exporter->exportTableForBatchInsert($table);

                    $this->info("  📥 Importing data to D1...");

                    $importer->importFromGenerator($table, $columns, $dataGenerator, $rowCount);
                }

                $this->newLine();
            }

            // Show final statistics
            $this->showStatistics($importer);

            $this->info('╔════════════════════════════════════════════════════════════════╗');
            $this->info('║                   ✅ Migration Complete!                       ║');
            $this->info('╚════════════════════════════════════════════════════════════════╝');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Migration failed: '.$e->getMessage());
            $this->error('Stack trace: '.$e->getTraceAsString());

            $this->showStatistics($importer);

            return self::FAILURE;
        } finally {
            $exporter->close();
        }
    }

    /**
     * Show migration statistics
     */
    protected function showStatistics(DataImporter $importer): void
    {
        $stats = $importer->getStats();

        $this->newLine();
        $this->info('📊 Migration Statistics:');
        $this->info("   Tables created: {$stats['tables_created']}");
        $this->info("   Indexes created: {$stats['indexes_created']}");
        $this->info("   Rows imported: ".number_format($stats['rows_imported']));
        $this->info("   Batches executed: {$stats['batches_executed']}");

        if (! empty($stats['errors'])) {
            $this->newLine();
            $this->error('⚠️  Errors encountered:');

            foreach ($stats['errors'] as $error) {
                $this->error("   • Table {$error['table']}: {$error['message']}");
            }
        }

        $this->newLine();
    }
}
