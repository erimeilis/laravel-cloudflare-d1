<?php

namespace EriMeilis\CloudflareD1\Database;

use Illuminate\Database\Connection;
use EriMeilis\CloudflareD1\Database\Query\D1QueryGrammar;
use EriMeilis\CloudflareD1\Database\Schema\D1SchemaGrammar;

class D1Connection extends Connection
{
    /**
     * Get the default query grammar instance
     */
    protected function getDefaultQueryGrammar(): D1QueryGrammar
    {
        return new D1QueryGrammar($this);
    }

    /**
     * Get a schema builder instance for the connection
     */
    public function getSchemaBuilder(): \Illuminate\Database\Schema\Builder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new \Illuminate\Database\Schema\Builder($this);
    }

    /**
     * Get the default schema grammar instance
     */
    protected function getDefaultSchemaGrammar(): D1SchemaGrammar
    {
        return new D1SchemaGrammar($this);
    }

    /**
     * Get the default post processor instance
     */
    protected function getDefaultPostProcessor(): \Illuminate\Database\Query\Processors\Processor
    {
        return new \Illuminate\Database\Query\Processors\Processor;
    }

    /**
     * Execute a PRAGMA statement
     * D1/SQLite specific command for database configuration
     */
    public function pragma(string $name, mixed $value = null): mixed
    {
        if ($value !== null) {
            $this->statement("PRAGMA {$name} = {$value}");

            return null;
        }

        return $this->selectOne("PRAGMA {$name}");
    }

    /**
     * Enable foreign key constraints
     * Critical for D1 as they're disabled by default in SQLite
     */
    public function enableForeignKeyConstraints(): bool
    {
        $this->pragma('foreign_keys', 'ON');

        return true;
    }

    /**
     * Disable foreign key constraints
     */
    public function disableForeignKeyConstraints(): bool
    {
        $this->pragma('foreign_keys', 'OFF');

        return true;
    }

    /**
     * Run an insert statement against the database
     * Override to convert bulk inserts to raw SQL to bypass D1's 100 parameter limit
     */
    public function insert($query, $bindings = []): bool
    {
        // Check if this is a bulk insert (multiple rows)
        if ($this->isBulkInsert($query, $bindings)) {
            return $this->insertUsingRawSql($query, $bindings);
        }

        // Single row insert or non-INSERT query, use parent
        return parent::insert($query, $bindings);
    }

    /**
     * Check if this is a bulk INSERT statement
     */
    protected function isBulkInsert(string $sql, array $bindings): bool
    {
        // Must be INSERT statement
        if (! preg_match('/^\s*INSERT\s+INTO\s+/i', $sql)) {
            return false;
        }

        // Count placeholders - if more than 10, it's likely a bulk insert
        $placeholderCount = substr_count($sql, '?');

        return $placeholderCount > 10;
    }

    /**
     * Execute bulk INSERT using raw SQL to leverage D1's 100KB limit
     */
    protected function insertUsingRawSql(string $sql, array $bindings): bool
    {
        // Extract table name and columns
        if (! preg_match('/^\s*INSERT\s+INTO\s+("?\w+"?)\s*\((.*?)\)\s*VALUES\s*(.+)/is', $sql, $matches)) {
            // Fallback to parent if pattern doesn't match
            return parent::insert($sql, $bindings);
        }

        $tableName = $matches[1];
        $columns = $matches[2];

        // Count columns to determine rows
        $columnCount = substr_count($columns, ',') + 1;

        if ($columnCount === 0 || count($bindings) % $columnCount !== 0) {
            // Fallback if we can't determine structure
            return parent::insert($sql, $bindings);
        }

        // Build raw SQL with escaped values
        $maxSqlSize = 95000; // 95KB to stay under 100KB limit
        $valueRows = [];
        $currentBatchSize = 0;

        for ($i = 0; $i < count($bindings); $i += $columnCount) {
            $rowValues = array_slice($bindings, $i, $columnCount);

            // Escape and format values
            $escapedValues = array_map(function ($value) {
                return $this->escapeValue($value);
            }, $rowValues);

            $valueRow = '('.implode(', ', $escapedValues).')';
            $valueRowSize = strlen($valueRow);

            // If adding this row would exceed max SQL size, execute current batch
            if ($currentBatchSize + $valueRowSize > $maxSqlSize && ! empty($valueRows)) {
                $rawSql = sprintf(
                    'INSERT INTO %s (%s) VALUES %s',
                    $tableName,
                    $columns,
                    implode(', ', $valueRows)
                );

                $this->statement($rawSql);

                $valueRows = [];
                $currentBatchSize = 0;
            }

            $valueRows[] = $valueRow;
            $currentBatchSize += $valueRowSize;
        }

        // Execute remaining rows
        if (! empty($valueRows)) {
            $rawSql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $tableName,
                $columns,
                implode(', ', $valueRows)
            );

            return $this->statement($rawSql);
        }

        return true;
    }

    /**
     * Escape a value for use in raw SQL (SQLite-compatible)
     */
    protected function escapeValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        // String escaping: SQLite uses single quotes and doubles single quotes for escaping
        return "'".str_replace("'", "''", (string) $value)."'";
    }

    /**
     * Get the driver name
     */
    public function getDriverName(): string
    {
        return 'd1';
    }

    /**
     * Get the database connection server version
     * D1 uses SQLite, report a compatible version
     */
    public function getServerVersion(): string
    {
        return '3.40.0'; // D1 is based on modern SQLite 3.40+
    }
}
