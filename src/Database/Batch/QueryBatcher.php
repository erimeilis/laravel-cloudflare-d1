<?php

namespace EriMeilis\CloudflareD1\Database\Batch;

use EriMeilis\CloudflareD1\Http\D1ApiClient;

class QueryBatcher
{
    protected D1ApiClient $apiClient;

    protected array $queryBuffer = [];

    protected bool $enabled = false;

    protected int $maxBatchSize = 50;

    protected array $results = [];

    public function __construct(D1ApiClient $apiClient, int $maxBatchSize = 50)
    {
        $this->apiClient = $apiClient;
        $this->maxBatchSize = $maxBatchSize;
    }

    /**
     * Enable batching mode.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable batching mode.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Check if batching is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Add a query to the batch.
     *
     * @param string $sql      SQL query
     * @param array  $bindings Parameter bindings
     *
     * @return int Query index in the batch
     */
    public function add(string $sql, array $bindings = []): int
    {
        $queryId = count($this->queryBuffer);

        $this->queryBuffer[] = [
            'sql'    => $sql,
            'params' => $bindings,
        ];

        // Auto-flush if batch size exceeded
        if (count($this->queryBuffer) >= $this->maxBatchSize) {
            $this->flush();
        }

        return $queryId;
    }

    /**
     * Execute all batched queries as a single API call.
     *
     * @return array Results indexed by query ID
     */
    public function flush(): array
    {
        if (empty($this->queryBuffer)) {
            return [];
        }

        try {
            // Split into chunks if exceeding parameter limits (100 params per query in SQLite)
            $chunks = $this->chunkByParameterCount($this->queryBuffer);

            $allResults = [];
            $resultIndex = 0;

            foreach ($chunks as $chunk) {
                $chunkResults = $this->apiClient->batch($chunk);

                foreach ($chunkResults as $result) {
                    $allResults[$resultIndex++] = $result;
                }
            }

            $this->results = $allResults;
            $this->queryBuffer = [];

            return $allResults;
        } catch (\Exception $e) {
            $this->queryBuffer = [];

            throw $e;
        }
    }

    /**
     * Clear the query buffer without executing.
     */
    public function clear(): void
    {
        $this->queryBuffer = [];
        $this->results = [];
    }

    /**
     * Get results from last flush.
     *
     * @param int $queryId Query ID from add() method
     *
     * @return array|null Result for specific query
     */
    public function getResult(int $queryId): ?array
    {
        return $this->results[$queryId] ?? null;
    }

    /**
     * Get all results from last flush.
     */
    public function getAllResults(): array
    {
        return $this->results;
    }

    /**
     * Get the number of queries in the current batch.
     */
    public function count(): int
    {
        return count($this->queryBuffer);
    }

    /**
     * Chunk queries by parameter count to respect SQLite's 100 parameter limit.
     *
     * @param array $queries Array of query statements
     *
     * @return array Array of chunked query sets
     */
    protected function chunkByParameterCount(array $queries): array
    {
        $chunks = [];
        $currentChunk = [];
        $currentParamCount = 0;
        $maxParams = 100;

        foreach ($queries as $query) {
            $paramCount = count($query['params'] ?? []);

            // If single query exceeds limit, it needs special handling
            if ($paramCount > $maxParams) {
                // Flush current chunk if any
                if (!empty($currentChunk)) {
                    $chunks[] = $currentChunk;
                    $currentChunk = [];
                    $currentParamCount = 0;
                }

                // Split this query into multiple queries
                $chunks = array_merge($chunks, $this->splitLargeQuery($query, $maxParams));

                continue;
            }

            // Check if adding this query would exceed the limit
            if ($currentParamCount + $paramCount > $maxParams && !empty($currentChunk)) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $currentParamCount = 0;
            }

            $currentChunk[] = $query;
            $currentParamCount += $paramCount;
        }

        // Add remaining queries
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        return empty($chunks) ? [$queries] : $chunks;
    }

    /**
     * Split a query with too many parameters into multiple queries
     * Primarily for bulk INSERT statements
     * Uses raw SQL with escaped values to leverage D1's 100KB limit instead of 100 parameter limit.
     */
    protected function splitLargeQuery(array $query, int $maxParams): array
    {
        $sql = $query['sql'];
        $params = $query['params'];

        // Check if it's a bulk INSERT
        if (!preg_match('/^\s*INSERT\s+INTO\s+("?\w+"?)\s*\((.*?)\)\s*VALUES\s*(.+)/is', $sql, $matches)) {
            // Not a bulk INSERT, return as single query and let D1 handle the error
            return [[$query]];
        }

        $tableName = $matches[1];
        $columns = $matches[2];

        // Count columns
        $columnCount = substr_count($columns, ',') + 1;

        if ($columnCount === 0) {
            return [[$query]];
        }

        // Convert to raw SQL approach: D1 supports 100KB of raw SQL vs only 100 parameters
        // This allows MUCH larger batches (hundreds of rows instead of ~10)
        $maxSqlSize = 95000; // 95KB to stay safely under 100KB limit

        // Build value rows with escaped values
        $valueRows = [];
        $currentBatchSize = 0;
        $queries = [];

        for ($i = 0; $i < count($params); $i += $columnCount) {
            $rowValues = array_slice($params, $i, $columnCount);

            // Escape and format values
            $escapedValues = array_map(function ($value) {
                return $this->escapeValue($value);
            }, $rowValues);

            $valueRow = '('.implode(', ', $escapedValues).')';
            $valueRowSize = strlen($valueRow);

            // If adding this row would exceed max SQL size, flush current batch
            if ($currentBatchSize + $valueRowSize > $maxSqlSize && !empty($valueRows)) {
                $rawSql = sprintf(
                    'INSERT INTO %s (%s) VALUES %s',
                    $tableName,
                    $columns,
                    implode(', ', $valueRows)
                );

                $queries[] = [[
                    'sql'    => $rawSql,
                    'params' => [], // No parameters for raw SQL
                ]];

                $valueRows = [];
                $currentBatchSize = 0;
            }

            $valueRows[] = $valueRow;
            $currentBatchSize += $valueRowSize;
        }

        // Add remaining rows
        if (!empty($valueRows)) {
            $rawSql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $tableName,
                $columns,
                implode(', ', $valueRows)
            );

            $queries[] = [[
                'sql'    => $rawSql,
                'params' => [], // No parameters for raw SQL
            ]];
        }

        return empty($queries) ? [[$query]] : $queries;
    }

    /**
     * Escape a value for use in raw SQL (SQLite-compatible).
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
     * Set maximum batch size.
     */
    public function setMaxBatchSize(int $size): void
    {
        $this->maxBatchSize = max(1, min($size, 100));
    }

    /**
     * Get maximum batch size.
     */
    public function getMaxBatchSize(): int
    {
        return $this->maxBatchSize;
    }
}
