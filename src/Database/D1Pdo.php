<?php

namespace EriMeilis\CloudflareD1\Database;

use EriMeilis\CloudflareD1\Database\Batch\QueryBatcher;
use EriMeilis\CloudflareD1\Http\D1ApiClient;
use PDO;
use PDOException;

class D1Pdo extends PDO
{
    protected D1ApiClient $apiClient;

    protected QueryBatcher $batcher;

    protected bool $inTransaction = false;

    protected ?string $lastInsertId = null;

    protected array $attributes = [];

    public function __construct(D1ApiClient $apiClient)
    {
        // Don't call parent::__construct() - we're not using a real PDO connection
        $this->apiClient = $apiClient;
        $this->batcher = new QueryBatcher($apiClient);

        // Set default attributes
        $this->attributes = [
            PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_CASE              => PDO::CASE_NATURAL,
            PDO::ATTR_ORACLE_NULLS      => PDO::NULL_NATURAL,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }

    /**
     * Prepare a statement for execution.
     */
    public function prepare(string $query, array $options = []): D1PdoStatement|false
    {
        try {
            return new D1PdoStatement($this, $query);
        } catch (\Exception $e) {
            if ($this->attributes[PDO::ATTR_ERRMODE] === PDO::ERRMODE_EXCEPTION) {
                throw new PDOException($e->getMessage(), (int) $e->getCode(), $e);
            }

            return false;
        }
    }

    /**
     * Execute an SQL statement and return the number of affected rows.
     */
    public function exec(string $statement): int|false
    {
        try {
            $stmt = $this->prepare($statement);
            if (!$stmt) {
                return false;
            }

            $stmt->execute();

            return $stmt->rowCount();
        } catch (\Exception $e) {
            if ($this->attributes[PDO::ATTR_ERRMODE] === PDO::ERRMODE_EXCEPTION) {
                throw new PDOException($e->getMessage(), (int) $e->getCode(), $e);
            }

            return false;
        }
    }

    /**
     * Execute a query and return a statement.
     */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): D1PdoStatement|false
    {
        try {
            $stmt = $this->prepare($query);
            if (!$stmt) {
                return false;
            }

            $stmt->execute();

            if ($fetchMode !== null) {
                $stmt->setFetchMode($fetchMode, ...$fetchModeArgs);
            }

            return $stmt;
        } catch (\Exception $e) {
            if ($this->attributes[PDO::ATTR_ERRMODE] === PDO::ERRMODE_EXCEPTION) {
                throw new PDOException($e->getMessage(), (int) $e->getCode(), $e);
            }

            return false;
        }
    }

    /**
     * Begin a transaction (enables query batching for performance).
     */
    public function beginTransaction(): bool
    {
        $this->inTransaction = true;
        $this->batcher->enable();

        return true;
    }

    /**
     * Commit a transaction (flushes batched queries).
     */
    public function commit(): bool
    {
        try {
            if ($this->inTransaction) {
                $this->batcher->flush();
                $this->inTransaction = false;
                $this->batcher->disable();
            }

            return true;
        } catch (\Exception $e) {
            throw new PDOException('Transaction commit failed: '.$e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Roll back a transaction (clears batched queries without executing).
     */
    public function rollBack(): bool
    {
        if ($this->inTransaction) {
            $this->batcher->clear();
            $this->inTransaction = false;
            $this->batcher->disable();
        }

        return true;
    }

    /**
     * Check if currently in a transaction.
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Get the ID of the last inserted row.
     */
    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastInsertId ?? false;
    }

    /**
     * Set the last insert ID (called by D1PdoStatement after INSERT).
     */
    public function setLastInsertId(?string $id): void
    {
        $this->lastInsertId = $id;
    }

    /**
     * Get the D1 API client.
     */
    public function getApiClient(): D1ApiClient
    {
        return $this->apiClient;
    }

    /**
     * Get the query batcher.
     */
    public function getBatcher(): QueryBatcher
    {
        return $this->batcher;
    }

    /**
     * Set a PDO attribute.
     */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->attributes[$attribute] = $value;

        return true;
    }

    /**
     * Get a PDO attribute.
     */
    public function getAttribute(int $attribute): mixed
    {
        return $this->attributes[$attribute] ?? null;
    }

    /**
     * Quote a string for use in a query (SQLite-compatible).
     * Required because parent PDO internals are not initialized.
     */
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'".str_replace("'", "''", $string)."'";
    }

    /**
     * Return the SQLSTATE error code.
     * Required because parent PDO internals are not initialized.
     */
    public function errorCode(): ?string
    {
        return null;
    }

    /**
     * Return extended error information.
     * Required because parent PDO internals are not initialized.
     */
    public function errorInfo(): array
    {
        return ['00000', null, null];
    }
}
