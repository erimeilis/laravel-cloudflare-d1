<?php

namespace EriMeilis\CloudflareD1\Migration;

use PDO;
use RuntimeException;

/**
 * DataExporter - Extract data from MySQL database for migration to D1.
 *
 * Features:
 * - Chunked data export for memory efficiency
 * - Respects SQLite's 100 parameter limit
 * - Progress reporting via callbacks
 * - Data type conversion (MySQL → SQLite)
 * - Table structure analysis
 * - Configurable batch sizes
 */
class DataExporter
{
    protected PDO $pdo;

    protected int $chunkSize = 1000;

    protected int $maxParameters = 100;

    /**
     * Progress callback function.
     *
     * @var callable|null
     */
    protected $progressCallback = null;

    /**
     * Create a new DataExporter instance.
     *
     * @param string $host     MySQL host
     * @param string $database Database name
     * @param string $username Username
     * @param string $password Password
     * @param int    $port     Port (default 3306)
     */
    public function __construct(
        string $host,
        string $database,
        string $username,
        string $password,
        int $port = 3306
    ) {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        try {
            $this->pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $e) {
            throw new RuntimeException("Failed to connect to MySQL: {$e->getMessage()}");
        }
    }

    /**
     * Set chunk size for data export.
     */
    public function setChunkSize(int $size): self
    {
        $this->chunkSize = $size;

        return $this;
    }

    /**
     * Set maximum parameters per query.
     */
    public function setMaxParameters(int $max): self
    {
        $this->maxParameters = $max;

        return $this;
    }

    /**
     * Set progress callback.
     *
     * Callback receives: function(string $table, int $exported, int $total)
     */
    public function onProgress(callable $callback): self
    {
        $this->progressCallback = $callback;

        return $this;
    }

    /**
     * Get list of all tables in the database.
     */
    public function getTables(): array
    {
        $stmt = $this->pdo->query('SHOW TABLES');

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get table structure (CREATE TABLE statement).
     */
    public function getTableStructure(string $table): string
    {
        $stmt = $this->pdo->prepare('SHOW CREATE TABLE '.$this->quoteIdentifier($table));
        $stmt->execute();

        $result = $stmt->fetch();

        return $result['Create Table'] ?? '';
    }

    /**
     * Get table row count.
     */
    public function getTableCount(string $table): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM '.$this->quoteIdentifier($table));
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get table column information.
     */
    public function getTableColumns(string $table): array
    {
        $stmt = $this->pdo->prepare('SHOW COLUMNS FROM '.$this->quoteIdentifier($table));
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Export all data from a table in chunks.
     *
     * @return \Generator Yields arrays of rows
     */
    public function exportTable(string $table): \Generator
    {
        $total = $this->getTableCount($table);
        $exported = 0;
        $offset = 0;

        // Get primary key for consistent ordering
        $primaryKey = $this->getPrimaryKey($table);
        $orderBy = $primaryKey ? "ORDER BY {$primaryKey}" : '';

        while ($offset < $total) {
            $sql = "SELECT * FROM {$this->quoteIdentifier($table)} {$orderBy} LIMIT {$this->chunkSize} OFFSET {$offset}";

            $stmt = $this->pdo->query($sql);
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                break;
            }

            // Convert data types for SQLite compatibility
            $rows = array_map(fn ($row) => $this->convertRowData($row), $rows);

            yield $rows;

            $exported += count($rows);
            $offset += $this->chunkSize;

            // Report progress
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, $table, $exported, $total);
            }
        }
    }

    /**
     * Export table data optimized for batch INSERT.
     *
     * Automatically splits into batches that respect the parameter limit
     *
     * @return \Generator Yields ['columns' => [...], 'rows' => [...]]
     */
    public function exportTableForBatchInsert(string $table): \Generator
    {
        $columns = array_column($this->getTableColumns($table), 'Field');
        $columnCount = count($columns);

        // Calculate max rows per batch based on parameter limit
        $maxRowsPerBatch = floor($this->maxParameters / $columnCount);

        if ($maxRowsPerBatch < 1) {
            throw new RuntimeException(
                "Table '{$table}' has {$columnCount} columns, exceeding the {$this->maxParameters} parameter limit"
            );
        }

        foreach ($this->exportTable($table) as $rows) {
            // Split rows into batches that respect parameter limit
            $batches = array_chunk($rows, (int) $maxRowsPerBatch);

            foreach ($batches as $batch) {
                yield [
                    'columns' => $columns,
                    'rows'    => $batch,
                ];
            }
        }
    }

    /**
     * Get primary key column name.
     */
    protected function getPrimaryKey(string $table): ?string
    {
        $stmt = $this->pdo->prepare("SHOW KEYS FROM {$this->quoteIdentifier($table)} WHERE Key_name = 'PRIMARY'");
        $stmt->execute();

        $result = $stmt->fetch();

        return $result ? $this->quoteIdentifier($result['Column_name']) : null;
    }

    /**
     * Convert row data from MySQL to SQLite-compatible format.
     */
    protected function convertRowData(array $row): array
    {
        return array_map(function ($value) {
            // NULL values
            if ($value === null) {
                return null;
            }

            // Boolean values (MySQL uses TINYINT)
            if ($value === '0' || $value === 0) {
                return 0;
            }

            if ($value === '1' || $value === 1) {
                return 1;
            }

            // Datetime values - keep as TEXT (SQLite uses TEXT for dates)
            // MySQL formats are already compatible: 'YYYY-MM-DD HH:MM:SS'

            return $value;
        }, $row);
    }

    /**
     * Quote identifier (table or column name).
     */
    protected function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * Export entire database structure and data.
     *
     * @return array ['tables' => [...], 'total_rows' => int]
     */
    public function exportDatabase(): array
    {
        $tables = $this->getTables();
        $export = [
            'tables'     => [],
            'total_rows' => 0,
        ];

        foreach ($tables as $table) {
            $tableData = [
                'name'      => $table,
                'structure' => $this->getTableStructure($table),
                'columns'   => array_column($this->getTableColumns($table), 'Field'),
                'row_count' => $this->getTableCount($table),
                'data'      => [],
            ];

            // Export all data
            foreach ($this->exportTable($table) as $rows) {
                $tableData['data'] = array_merge($tableData['data'], $rows);
            }

            $export['tables'][$table] = $tableData;
            $export['total_rows'] += $tableData['row_count'];
        }

        return $export;
    }

    /**
     * Export database metadata only (no data).
     */
    public function exportMetadata(): array
    {
        $tables = $this->getTables();
        $metadata = [];

        foreach ($tables as $table) {
            $metadata[$table] = [
                'name'      => $table,
                'structure' => $this->getTableStructure($table),
                'columns'   => array_column($this->getTableColumns($table), 'Field'),
                'row_count' => $this->getTableCount($table),
            ];
        }

        return $metadata;
    }

    /**
     * Validate connection and database access.
     */
    public function validate(): bool
    {
        try {
            $this->pdo->query('SELECT 1');

            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Get the PDO connection.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Close the database connection.
     */
    public function close(): void
    {
        $this->pdo = null;
    }
}
