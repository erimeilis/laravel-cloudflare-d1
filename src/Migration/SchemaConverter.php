<?php

namespace EriMeilis\CloudflareD1\Migration;

use RuntimeException;

/**
 * SchemaConverter - Convert MySQL schemas to SQLite/D1 compatible schemas.
 *
 * Handles:
 * - Data type mapping (INT → INTEGER, VARCHAR → TEXT, etc.)
 * - AUTO_INCREMENT → AUTOINCREMENT
 * - ENUM → TEXT with CHECK constraint
 * - Foreign keys, indexes, unique constraints
 * - Default values and timestamps
 * - MySQL-specific features to SQLite equivalents
 */
class SchemaConverter
{
    /**
     * Data type mapping from MySQL to SQLite.
     */
    protected array $typeMap = [
        // Integer types
        'TINYINT'   => 'INTEGER',
        'SMALLINT'  => 'INTEGER',
        'MEDIUMINT' => 'INTEGER',
        'INT'       => 'INTEGER',
        'INTEGER'   => 'INTEGER',
        'BIGINT'    => 'INTEGER',

        // String types
        'CHAR'       => 'TEXT',
        'VARCHAR'    => 'TEXT',
        'TINYTEXT'   => 'TEXT',
        'TEXT'       => 'TEXT',
        'MEDIUMTEXT' => 'TEXT',
        'LONGTEXT'   => 'TEXT',

        // Numeric types
        'DECIMAL' => 'REAL',
        'NUMERIC' => 'REAL',
        'FLOAT'   => 'REAL',
        'DOUBLE'  => 'REAL',
        'REAL'    => 'REAL',

        // Date/Time types
        'DATE'      => 'TEXT',
        'DATETIME'  => 'TEXT',
        'TIMESTAMP' => 'TEXT',
        'TIME'      => 'TEXT',
        'YEAR'      => 'TEXT',

        // Binary types
        'BINARY'     => 'BLOB',
        'VARBINARY'  => 'BLOB',
        'TINYBLOB'   => 'BLOB',
        'BLOB'       => 'BLOB',
        'MEDIUMBLOB' => 'BLOB',
        'LONGBLOB'   => 'BLOB',

        // Other types
        'BOOLEAN' => 'INTEGER',
        'BOOL'    => 'INTEGER',
        'JSON'    => 'TEXT',
        'ENUM'    => 'TEXT', // Special handling required
        'SET'     => 'TEXT',  // Special handling required
    ];

    /**
     * Warnings generated during conversion.
     */
    protected array $warnings = [];

    /**
     * Convert MySQL CREATE TABLE statement to SQLite.
     *
     * @param string $mysqlSql MySQL CREATE TABLE statement
     *
     * @return string SQLite CREATE TABLE statement
     */
    public function convert(string $mysqlSql): string
    {
        $this->warnings = [];

        // Parse the CREATE TABLE statement
        $parsed = $this->parseCreateTable($mysqlSql);

        // Build SQLite CREATE TABLE
        return $this->buildCreateTable($parsed);
    }

    /**
     * Get conversion warnings.
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Parse MySQL CREATE TABLE statement.
     */
    public function parseCreateTable(string $sql): array
    {
        // Remove comments
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        // Extract table name
        if (!preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $matches)) {
            throw new RuntimeException('Could not parse table name from CREATE TABLE statement');
        }

        $tableName = $matches[1];

        // Extract content between parentheses
        if (!preg_match('/\((.*)\)\s*(?:ENGINE|CHARACTER|COLLATE|$)/is', $sql, $matches)) {
            throw new RuntimeException('Could not parse table definition');
        }

        $definition = $matches[1];

        // Parse columns and constraints
        $columns = [];
        $primaryKey = null;
        $foreignKeys = [];
        $uniqueKeys = [];
        $indexes = [];

        // Split by comma (being careful about nested parentheses)
        $parts = $this->splitDefinition($definition);

        foreach ($parts as $part) {
            $part = trim($part);

            if (preg_match('/^PRIMARY\s+KEY/i', $part)) {
                $primaryKey = $this->parsePrimaryKey($part);
            } elseif (preg_match('/^(?:CONSTRAINT\s+\w+\s+)?FOREIGN\s+KEY/i', $part)) {
                $foreignKeys[] = $this->parseForeignKey($part);
            } elseif (preg_match('/^(?:FULLTEXT|SPATIAL)\s+(?:KEY|INDEX)/i', $part)) {
                // FULLTEXT/SPATIAL indexes - skip with warning
                if (preg_match('/(?:KEY|INDEX)\s+`?(\w+)`?/i', $part, $matches)) {
                    $name = $matches[1];
                    $this->warnings[] = stripos($part, 'FULLTEXT') !== false
                        ? "FULLTEXT index '{$name}' not supported - consider using external search"
                        : "SPATIAL index '{$name}' not supported";
                }
            } elseif (preg_match('/^UNIQUE\s+(?:KEY|INDEX)/i', $part)) {
                // UNIQUE INDEX - parse as index
                $indexes[] = $this->parseIndex($part);
            } elseif (preg_match('/^(?:CONSTRAINT\s+\w+\s+)?UNIQUE/i', $part)) {
                // UNIQUE constraint (no INDEX keyword)
                $uniqueKeys[] = $this->parseUniqueKey($part);
            } elseif (preg_match('/^(?:KEY|INDEX)/i', $part)) {
                $indexes[] = $this->parseIndex($part);
            } else {
                // Column definition
                $column = $this->parseColumn($part);
                if ($column) {
                    $columns[] = $column;
                }
            }
        }

        return [
            'table'        => $tableName,
            'columns'      => $columns,
            'primary_key'  => $primaryKey,
            'foreign_keys' => $foreignKeys,
            'unique_keys'  => $uniqueKeys,
            'indexes'      => $indexes,
        ];
    }

    /**
     * Split definition by top-level commas (respecting parentheses).
     */
    protected function splitDefinition(string $definition): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        for ($i = 0; $i < strlen($definition); $i++) {
            $char = $definition[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if ($current) {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Parse column definition.
     */
    protected function parseColumn(string $definition): ?array
    {
        // Match: column_name TYPE(size) [UNSIGNED] [NOT NULL] [DEFAULT value] [AUTO_INCREMENT] [PRIMARY KEY]
        if (!preg_match('/^`?(\w+)`?\s+(\w+)(?:\(([^)]+)\))?(.*)$/i', $definition, $matches)) {
            return null;
        }

        $name = $matches[1];
        $type = strtoupper($matches[2]);
        $size = $matches[3] ?? null;
        $modifiers = $matches[4] ?? '';

        // Handle ENUM specially
        $enumValues = null;
        if ($type === 'ENUM') {
            if (preg_match('/ENUM\s*\((.*?)\)/i', $definition, $enumMatch)) {
                $enumValues = $enumMatch[1];
                $this->warnings[] = "Column '{$name}': ENUM converted to TEXT with CHECK constraint";
            }
        }

        // Map to SQLite type
        $sqliteType = $this->typeMap[$type] ?? 'TEXT';

        // Check for type-specific warnings
        if (in_array($type, ['DECIMAL', 'NUMERIC']) && $sqliteType === 'REAL') {
            $this->warnings[] = "Column '{$name}': {$type} converted to REAL - precision may be lost";
        }

        // Parse modifiers
        $notNull = stripos($modifiers, 'NOT NULL') !== false;
        $autoIncrement = stripos($modifiers, 'AUTO_INCREMENT') !== false;
        $primary = stripos($modifiers, 'PRIMARY KEY') !== false;
        $unique = stripos($modifiers, 'UNIQUE') !== false;

        // Parse DEFAULT value
        $default = null;
        if (preg_match('/DEFAULT\s+([^\s,]+|\'[^\']*\'|"[^"]*")/i', $modifiers, $defaultMatch)) {
            $default = trim($defaultMatch[1], '\'"');

            // Convert MySQL-specific defaults
            if (strtoupper($default) === 'CURRENT_TIMESTAMP') {
                if ($sqliteType === 'TEXT') {
                    $default = "(datetime('now'))";
                }
            } elseif (is_numeric($default)) {
                // Keep as is
            } else {
                // Quote string defaults
                $default = "'".str_replace("'", "''", $default)."'";
            }
        }

        return [
            'name'           => $name,
            'type'           => $sqliteType,
            'size'           => $size,
            'not_null'       => $notNull,
            'default'        => $default,
            'auto_increment' => $autoIncrement,
            'primary'        => $primary,
            'unique'         => $unique,
            'enum_values'    => $enumValues,
        ];
    }

    /**
     * Parse PRIMARY KEY constraint.
     */
    protected function parsePrimaryKey(string $definition): ?array
    {
        if (preg_match('/PRIMARY\s+KEY\s*\(([^)]+)\)/i', $definition, $matches)) {
            $columns = array_map(
                fn ($col) => trim($col, '` '),
                explode(',', $matches[1])
            );

            return ['columns' => $columns];
        }

        return null;
    }

    /**
     * Parse FOREIGN KEY constraint.
     */
    protected function parseForeignKey(string $definition): ?array
    {
        // FOREIGN KEY (`column`) REFERENCES `table` (`ref_column`) [ON DELETE ...] [ON UPDATE ...]
        $pattern = '/FOREIGN\s+KEY\s*\(([^)]+)\)\s*REFERENCES\s+`?(\w+)`?\s*\(([^)]+)\)(.*)/i';

        if (preg_match($pattern, $definition, $matches)) {
            $columns = array_map(fn ($col) => trim($col, '` '), explode(',', $matches[1]));
            $refTable = $matches[2];
            $refColumns = array_map(fn ($col) => trim($col, '` '), explode(',', $matches[3]));
            $actions = $matches[4] ?? '';

            $onDelete = null;
            $onUpdate = null;

            if (preg_match('/ON\s+DELETE\s+(\w+(?:\s+\w+)?)/i', $actions, $m)) {
                $onDelete = strtoupper($m[1]);
            }

            if (preg_match('/ON\s+UPDATE\s+(\w+(?:\s+\w+)?)/i', $actions, $m)) {
                $onUpdate = strtoupper($m[1]);
            }

            return [
                'columns'     => $columns,
                'ref_table'   => $refTable,
                'ref_columns' => $refColumns,
                'on_delete'   => $onDelete,
                'on_update'   => $onUpdate,
            ];
        }

        return null;
    }

    /**
     * Parse UNIQUE constraint.
     */
    protected function parseUniqueKey(string $definition): ?array
    {
        if (preg_match('/UNIQUE(?:\s+KEY|\s+INDEX)?\s*(?:`?\w+`?)?\s*\(([^)]+)\)/i', $definition, $matches)) {
            $columns = array_map(
                fn ($col) => trim($col, '` '),
                explode(',', $matches[1])
            );

            return ['columns' => $columns];
        }

        return null;
    }

    /**
     * Parse INDEX.
     */
    protected function parseIndex(string $definition): ?array
    {
        if (preg_match('/(?:KEY|INDEX)\s+`?(\w+)`?\s*\(([^)]+)\)/i', $definition, $matches)) {
            $name = $matches[1];
            $columns = array_map(
                fn ($col) => trim($col, '` '),
                explode(',', $matches[2])
            );

            // Check if it's a FULLTEXT or SPATIAL index (should be caught earlier, but double-check)
            if (stripos($definition, 'FULLTEXT') !== false || stripos($definition, 'SPATIAL') !== false) {
                return null; // Skip - already warned
            }

            // Check if it's a UNIQUE index
            $unique = stripos($definition, 'UNIQUE') !== false;

            return [
                'name'    => $name,
                'columns' => $columns,
                'unique'  => $unique,
            ];
        }

        return null;
    }

    /**
     * Build SQLite CREATE TABLE statement.
     */
    protected function buildCreateTable(array $parsed): string
    {
        $lines = [];
        $tableName = $parsed['table'];

        // Build column definitions
        foreach ($parsed['columns'] as $column) {
            $line = $this->buildColumnDefinition($column, $parsed['primary_key']);
            if ($line) {
                $lines[] = $line;
            }
        }

        // Add table-level PRIMARY KEY if not on a single column
        if ($parsed['primary_key'] && count($parsed['primary_key']['columns']) > 1) {
            $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $parsed['primary_key']['columns']));
            $lines[] = "PRIMARY KEY ({$cols})";
        }

        // Add FOREIGN KEY constraints
        foreach ($parsed['foreign_keys'] as $fk) {
            $lines[] = $this->buildForeignKeyDefinition($fk);
        }

        // Add UNIQUE constraints
        foreach ($parsed['unique_keys'] as $uk) {
            $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $uk['columns']));
            $lines[] = "UNIQUE ({$cols})";
        }

        $sql = "CREATE TABLE `{$tableName}` (\n  ".implode(",\n  ", $lines)."\n)";

        return $sql;
    }

    /**
     * Build column definition.
     */
    protected function buildColumnDefinition(array $column, ?array $primaryKey): string
    {
        $parts = ["`{$column['name']}`", $column['type']];

        // Handle AUTO_INCREMENT PRIMARY KEY specially
        if ($column['auto_increment'] && $column['type'] === 'INTEGER' && $column['primary']) {
            $parts[] = 'PRIMARY KEY AUTOINCREMENT';
        }
        // Or if it's part of a single-column primary key
        elseif ($column['auto_increment'] && $column['type'] === 'INTEGER' &&
                 $primaryKey && count($primaryKey['columns']) === 1 &&
                 $primaryKey['columns'][0] === $column['name']) {
            $parts[] = 'PRIMARY KEY AUTOINCREMENT';
        }
        // Regular PRIMARY KEY
        elseif ($column['primary']) {
            $parts[] = 'PRIMARY KEY';
        }

        // NOT NULL
        if ($column['not_null'] && !$column['primary']) {
            $parts[] = 'NOT NULL';
        }

        // UNIQUE
        if ($column['unique']) {
            $parts[] = 'UNIQUE';
        }

        // DEFAULT
        if ($column['default'] !== null) {
            $parts[] = "DEFAULT {$column['default']}";
        }

        // ENUM CHECK constraint
        if ($column['enum_values']) {
            $values = str_replace('"', "'", $column['enum_values']);
            $parts[] = "CHECK(`{$column['name']}` IN ({$values}))";
        }

        return implode(' ', $parts);
    }

    /**
     * Build FOREIGN KEY definition.
     */
    protected function buildForeignKeyDefinition(array $fk): string
    {
        $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $fk['columns']));
        $refCols = implode(', ', array_map(fn ($c) => "`{$c}`", $fk['ref_columns']));

        $parts = ["FOREIGN KEY ({$cols}) REFERENCES `{$fk['ref_table']}` ({$refCols})"];

        if ($fk['on_delete']) {
            $parts[] = "ON DELETE {$fk['on_delete']}";
        }

        if ($fk['on_update']) {
            $parts[] = "ON UPDATE {$fk['on_update']}";
        }

        return implode(' ', $parts);
    }

    /**
     * Generate index creation statements (separate from CREATE TABLE).
     */
    public function buildIndexStatements(array $parsed): array
    {
        $statements = [];
        $tableName = $parsed['table'];

        foreach ($parsed['indexes'] as $index) {
            $indexName = $index['name'];
            $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $index['columns']));
            $unique = $index['unique'] ?? false;

            $createStmt = $unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
            $statements[] = "{$createStmt} `{$indexName}` ON `{$tableName}` ({$cols})";
        }

        return $statements;
    }
}
