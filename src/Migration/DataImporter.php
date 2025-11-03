<?php

namespace EriMeilis\CloudflareD1\Migration;

use EriMeilis\CloudflareD1\Http\D1ApiClient;
use RuntimeException;

/**
 * DataImporter - Import data into Cloudflare D1 database
 *
 * Features:
 * - Batch INSERT operations for performance
 * - Automatic parameter limit handling
 * - Schema creation using SchemaConverter
 * - Progress reporting via callbacks
 * - Transaction support
 * - Error handling and recovery
 */
class DataImporter
{
    protected D1ApiClient $apiClient;

    protected SchemaConverter $schemaConverter;

    protected int $maxParameters = 100;

    /**
     * Progress callback function
     *
     * @var callable|null
     */
    protected $progressCallback = null;

    /**
     * Statistics tracking
     */
    protected array $stats = [
        'tables_created' => 0,
        'indexes_created' => 0,
        'rows_imported' => 0,
        'batches_executed' => 0,
        'errors' => [],
    ];

    /**
     * Create a new DataImporter instance
     */
    public function __construct(D1ApiClient $apiClient, ?SchemaConverter $schemaConverter = null)
    {
        $this->apiClient = $apiClient;
        $this->schemaConverter = $schemaConverter ?? new SchemaConverter;
    }

    /**
     * Set maximum parameters per query
     */
    public function setMaxParameters(int $max): self
    {
        $this->maxParameters = $max;

        return $this;
    }

    /**
     * Set progress callback
     *
     * Callback receives: function(string $operation, string $detail, int $current, int $total)
     */
    public function onProgress(callable $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    /**
     * Get import statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'tables_created' => 0,
            'indexes_created' => 0,
            'rows_imported' => 0,
            'batches_executed' => 0,
            'errors' => [],
        ];
    }

    /**
     * Create table from MySQL CREATE TABLE statement
     */
    public function createTable(string $mysqlCreateTable): array
    {
        // Convert MySQL schema to SQLite
        $sqliteCreateTable = $this->schemaConverter->convert($mysqlCreateTable);

        // Execute CREATE TABLE
        $result = $this->apiClient->raw($sqliteCreateTable);

        $this->stats['tables_created']++;

        // Get and create indexes
        $parsed = $this->schemaConverter->parseCreateTable($mysqlCreateTable);
        $indexStatements = $this->schemaConverter->buildIndexStatements($parsed);

        foreach ($indexStatements as $indexSql) {
            $this->apiClient->raw($indexSql);
            $this->stats['indexes_created']++;
        }

        // Report progress
        if ($this->progressCallback) {
            $tableName = $parsed['table'] ?? 'unknown';
            call_user_func($this->progressCallback, 'create_table', $tableName, 1, 1);
        }

        return [
            'table' => $sqliteCreateTable,
            'indexes' => $indexStatements,
            'warnings' => $this->schemaConverter->getWarnings(),
        ];
    }

    /**
     * Import data into a table
     *
     * @param  string  $table  Table name
     * @param  array  $columns  Column names
     * @param  array  $rows  Array of row data
     */
    public function importData(string $table, array $columns, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $columnCount = count($columns);
        $totalRows = count($rows);

        // Calculate max rows per batch based on parameter limit
        $maxRowsPerBatch = floor($this->maxParameters / $columnCount);

        if ($maxRowsPerBatch < 1) {
            throw new RuntimeException(
                "Table '{$table}' has {$columnCount} columns, exceeding the {$this->maxParameters} parameter limit"
            );
        }

        // Split rows into batches
        $batches = array_chunk($rows, (int) $maxRowsPerBatch);
        $totalBatches = count($batches);
        $currentBatch = 0;

        foreach ($batches as $batch) {
            $this->executeBatchInsert($table, $columns, $batch);

            $currentBatch++;
            $rowsImported = $currentBatch * $maxRowsPerBatch;
            $rowsImported = min($rowsImported, $totalRows);

            // Report progress
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, 'import_data', $table, $rowsImported, $totalRows);
            }
        }
    }

    /**
     * Execute a batch INSERT operation
     */
    protected function executeBatchInsert(string $table, array $columns, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $columnList = implode(', ', array_map(fn ($col) => "`{$col}`", $columns));

        $statements = [];

        foreach ($rows as $row) {
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql = "INSERT INTO `{$table}` ({$columnList}) VALUES ({$placeholders})";

            $params = array_values($row);

            $statements[] = [
                'sql' => $sql,
                'params' => $params,
            ];
        }

        try {
            // Use batch API for performance
            $this->apiClient->batch($statements);

            $this->stats['batches_executed']++;
            $this->stats['rows_imported'] += count($rows);
        } catch (\Exception $e) {
            $this->stats['errors'][] = [
                'table' => $table,
                'operation' => 'batch_insert',
                'rows' => count($rows),
                'message' => $e->getMessage(),
            ];

            throw new RuntimeException(
                "Failed to import batch into '{$table}': {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Import entire table (structure + data)
     *
     * @param  array  $tableData  ['structure' => string, 'columns' => array, 'data' => array]
     */
    public function importTable(array $tableData): void
    {
        // Create table structure
        $createResult = $this->createTable($tableData['structure']);

        // Import data in batches
        if (! empty($tableData['data'])) {
            $this->importData(
                $tableData['name'],
                $tableData['columns'],
                $tableData['data']
            );
        }
    }

    /**
     * Import entire database from DataExporter format
     *
     * @param  array  $exportData  Output from DataExporter::exportDatabase()
     */
    public function importDatabase(array $exportData): void
    {
        $this->resetStats();

        $tables = $exportData['tables'] ?? [];
        $totalTables = count($tables);
        $currentTable = 0;

        foreach ($tables as $tableName => $tableData) {
            try {
                $this->importTable($tableData);

                $currentTable++;

                // Report progress
                if ($this->progressCallback) {
                    call_user_func($this->progressCallback, 'import_database', $tableName, $currentTable, $totalTables);
                }
            } catch (\Exception $e) {
                $this->stats['errors'][] = [
                    'table' => $tableName,
                    'operation' => 'import_table',
                    'message' => $e->getMessage(),
                ];

                // Re-throw to stop import on error
                throw new RuntimeException(
                    "Failed to import table '{$tableName}': {$e->getMessage()}",
                    0,
                    $e
                );
            }
        }
    }

    /**
     * Import data from generator (memory efficient for large datasets)
     *
     * @param  string  $table  Table name
     * @param  array  $columns  Column names
     * @param  \Generator  $dataGenerator  Generator yielding row batches
     * @param  int  $totalRows  Total rows (for progress reporting)
     */
    public function importFromGenerator(string $table, array $columns, \Generator $dataGenerator, int $totalRows): void
    {
        $importedRows = 0;

        foreach ($dataGenerator as $batch) {
            $this->importData($table, $columns, $batch['rows']);

            $importedRows += count($batch['rows']);

            // Report progress
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, 'import_generator', $table, $importedRows, $totalRows);
            }
        }
    }

    /**
     * Truncate a table
     */
    public function truncateTable(string $table): void
    {
        $this->apiClient->raw("DELETE FROM `{$table}`");

        // Report progress
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, 'truncate', $table, 1, 1);
        }
    }

    /**
     * Drop a table
     */
    public function dropTable(string $table): void
    {
        $this->apiClient->raw("DROP TABLE IF EXISTS `{$table}`");

        // Report progress
        if ($this->progressCallback) {
            call_user_func($this->progressCallback, 'drop_table', $table, 1, 1);
        }
    }

    /**
     * Check if table exists
     */
    public function tableExists(string $table): bool
    {
        try {
            $result = $this->apiClient->raw(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
                [$table]
            );

            return ! empty($result[0]['results']['rows'] ?? []);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get list of all tables in D1 database
     */
    public function getTables(): array
    {
        $result = $this->apiClient->raw(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        );

        $rows = $result[0]['results']['rows'] ?? [];

        return array_map(fn ($row) => $row[0], $rows);
    }

    /**
     * Validate D1 connection
     */
    public function validate(): bool
    {
        try {
            $this->apiClient->raw('SELECT 1');

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the D1 API client
     */
    public function getApiClient(): D1ApiClient
    {
        return $this->apiClient;
    }
}
