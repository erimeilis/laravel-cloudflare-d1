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
