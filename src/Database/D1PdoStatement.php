<?php

namespace EriMeilis\CloudflareD1\Database;

use PDO;
use PDOException;
use PDOStatement;

class D1PdoStatement extends PDOStatement
{
    protected D1Pdo $pdo;

    public string $queryString; // Must be public to match PDOStatement

    protected array $bindings = [];

    protected ?array $results = null;

    protected int $cursor = 0;

    protected int $fetchMode = PDO::FETCH_BOTH;

    protected array $fetchModeArgs = [];

    protected ?array $columnNames = null;

    protected int $rowCount = 0;

    public function __construct(D1Pdo $pdo, string $query)
    {
        $this->pdo = $pdo;
        $this->queryString = $query;
    }

    /**
     * Bind a parameter to the specified variable
     */
    public function bindParam(
        string|int $param,
        mixed &$var,
        int $type = PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null
    ): bool {
        $this->bindings[$param] = &$var;

        return true;
    }

    /**
     * Bind a value to a parameter
     */
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = $value;

        return true;
    }

    /**
     * Execute the prepared statement
     */
    public function execute(?array $params = null): bool
    {
        try {
            // Merge provided params with bound params
            $bindings = $params ?? $this->bindings;

            // Convert named parameters (:param) to positional (?) for D1
            $sql = $this->queryString;
            $values = [];

            if ($this->hasNamedParameters($bindings)) {
                [$sql, $values] = $this->convertNamedToPositional($sql, $bindings);
            } else {
                $values = array_values($bindings);
            }

            // Check if in transaction (batching enabled)
            $batcher = $this->pdo->getBatcher();
            if ($batcher->isEnabled()) {
                // Add to batch
                $resultIndex = $batcher->add($sql, $values);
                // Results will be retrieved when transaction commits
                $this->results = [];
                $this->rowCount = 0;

                return true;
            }

            // Execute immediately via API
            $apiClient = $this->pdo->getApiClient();
            $response = $apiClient->raw($sql, $values);

            // Process results
            $this->processApiResponse($response);

            return true;
        } catch (\Exception $e) {
            throw new PDOException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Process API response and extract results
     */
    protected function processApiResponse(array $response): void
    {
        if (empty($response)) {
            $this->results = [];
            $this->rowCount = 0;

            return;
        }

        $result = $response[0] ?? [];

        // Extract column names for /raw endpoint
        $this->columnNames = $result['results']['columns'] ?? [];

        // Extract rows
        $rows = $result['results']['rows'] ?? $result['results'] ?? [];

        // Convert arrays to associative arrays using column names
        if (! empty($this->columnNames) && is_array($rows)) {
            $this->results = array_map(function ($row) {
                return array_combine($this->columnNames, $row);
            }, $rows);
        } else {
            $this->results = is_array($rows) ? $rows : [];
        }

        // Extract metadata
        $this->rowCount = $result['results']['rows_written']
            ?? $result['results']['rows_read']
            ?? count($this->results);

        // Handle last insert ID for INSERT statements
        if (preg_match('/^\s*INSERT\s+/i', $this->queryString)) {
            $lastId = $result['meta']['last_row_id'] ?? null;
            if ($lastId !== null) {
                $this->pdo->setLastInsertId((string) $lastId);
            }
        }

        $this->cursor = 0;
    }

    /**
     * Fetch a single row
     */
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        if ($mode === PDO::FETCH_DEFAULT) {
            $mode = $this->fetchMode;
        }

        if ($this->results === null || $this->cursor >= count($this->results)) {
            return false;
        }

        $row = $this->results[$this->cursor++];

        return $this->formatRow($row, $mode);
    }

    /**
     * Fetch all rows
     */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_DEFAULT) {
            $mode = $this->fetchMode;
        }

        if ($this->results === null) {
            return [];
        }

        return array_map(fn ($row) => $this->formatRow($row, $mode), $this->results);
    }

    /**
     * Fetch a single column from the next row
     */
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch(PDO::FETCH_NUM);

        return $row[$column] ?? false;
    }

    /**
     * Format a row based on fetch mode
     */
    protected function formatRow(array $row, int $mode): mixed
    {
        return match ($mode) {
            PDO::FETCH_ASSOC => $row,
            PDO::FETCH_NUM => array_values($row),
            PDO::FETCH_BOTH => $row + array_values($row),
            PDO::FETCH_OBJ => (object) $row,
            default => $row,
        };
    }

    /**
     * Get the number of rows affected by the last statement
     */
    public function rowCount(): int
    {
        return $this->rowCount;
    }

    /**
     * Get the number of columns in the result set
     */
    public function columnCount(): int
    {
        return count($this->columnNames ?? []);
    }

    /**
     * Set the fetch mode
     */
    public function setFetchMode(int $mode, mixed ...$args): true
    {
        $this->fetchMode = $mode;
        $this->fetchModeArgs = $args;

        return true;
    }

    /**
     * Check if bindings use named parameters
     */
    protected function hasNamedParameters(array $bindings): bool
    {
        foreach (array_keys($bindings) as $key) {
            if (is_string($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert named parameters (:param) to positional (?)
     */
    protected function convertNamedToPositional(string $sql, array $bindings): array
    {
        $values = [];
        $newSql = $sql;

        foreach ($bindings as $key => $value) {
            if (is_string($key)) {
                $placeholder = ':'.ltrim($key, ':');
                $newSql = str_replace($placeholder, '?', $newSql);
                $values[] = $value;
            }
        }

        return [$newSql, $values];
    }
}
