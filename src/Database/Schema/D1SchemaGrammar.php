<?php

namespace EriMeilis\CloudflareD1\Database\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Fluent;

class D1SchemaGrammar extends SQLiteGrammar
{
    /**
     * D1 uses SQLite schema syntax, so we extend SQLite schema grammar
     * This provides automatic compatibility with SQLite DDL statements.
     */

    /**
     * Compile the command to enable foreign key constraints.
     */
    public function compileEnableForeignKeyConstraints(): string
    {
        return 'PRAGMA foreign_keys = ON;';
    }

    /**
     * Compile the command to disable foreign key constraints.
     */
    public function compileDisableForeignKeyConstraints(): string
    {
        return 'PRAGMA foreign_keys = OFF;';
    }

    /**
     * Compile a create table command.
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        $primary = collect($this->getCommandsByName($blueprint, 'primary'))->first();

        return sprintf(
            '%s table %s (%s%s%s)',
            $command->temporary ? 'create temporary' : 'create',
            $this->wrapTable($blueprint),
            implode(', ', $this->getColumns($blueprint)),
            (string) $this->addForeignKeys($this->getCommandsByName($blueprint, 'foreign')),
            (string) $this->addPrimaryKeys($primary)
        );
    }

    /**
     * Compile an add column command.
     *
     * Note: SQLite has limited ALTER TABLE support
     * Adding multiple columns requires multiple statements
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): string
    {
        $columns = $this->prefixArray('add column', $this->getColumns($blueprint));

        return 'alter table '.$this->wrapTable($blueprint).' '.implode(', ', $columns);
    }

    /**
     * Get the SQL for a "autoincrement" column modifier.
     */
    protected function modifyIncrement(Blueprint $blueprint, Fluent $column): ?string
    {
        if (in_array($column->type, $this->serials) && $column->autoIncrement) {
            return ' primary key autoincrement';
        }

        return null;
    }

    /**
     * Compile a drop table command.
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table '.$this->wrapTable($blueprint);
    }

    /**
     * Compile a drop table (if exists) command.
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return 'drop table if exists '.$this->wrapTable($blueprint);
    }

    /**
     * Compile the SQL needed to drop all tables.
     *
     * @param string|null $schema
     */
    public function compileDropAllTables($schema = null): string
    {
        return 'delete from sqlite_master where type in (\'table\', \'index\', \'trigger\')';
    }

    /**
     * Compile a rename table command.
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        $from = $this->wrapTable($blueprint);

        return "alter table {$from} rename to ".$this->wrapTable($command->to);
    }

    /**
     * Create the column definition for a string type.
     */
    protected function typeString(Fluent $column): string
    {
        // SQLite doesn't enforce length, but we preserve it for compatibility
        return 'text';
    }

    /**
     * Create the column definition for a text type.
     */
    protected function typeText(Fluent $column): string
    {
        return 'text';
    }

    /**
     * Create the column definition for a big integer type.
     */
    protected function typeBigInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Create the column definition for an integer type.
     */
    protected function typeInteger(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Create the column definition for a float type.
     */
    protected function typeFloat(Fluent $column): string
    {
        return 'real';
    }

    /**
     * Create the column definition for a double type.
     */
    protected function typeDouble(Fluent $column): string
    {
        return 'real';
    }

    /**
     * Create the column definition for a decimal type.
     *
     * Note: SQLite stores DECIMAL as REAL, precision may be lost
     */
    protected function typeDecimal(Fluent $column): string
    {
        return 'real';
    }

    /**
     * Create the column definition for a boolean type.
     */
    protected function typeBoolean(Fluent $column): string
    {
        return 'integer';
    }

    /**
     * Create the column definition for a date type.
     */
    protected function typeDate(Fluent $column): string
    {
        return 'text';
    }

    /**
     * Create the column definition for a date-time type.
     */
    protected function typeDateTime(Fluent $column): string
    {
        return 'text';
    }

    /**
     * Create the column definition for a timestamp type.
     */
    protected function typeTimestamp(Fluent $column): string
    {
        if ($column->useCurrent) {
            return 'text default (datetime(\'now\'))';
        }

        return 'text';
    }

    /**
     * Create the column definition for a JSON type.
     */
    protected function typeJson(Fluent $column): string
    {
        return 'text';
    }

    /**
     * Create the column definition for a JSONB type.
     */
    protected function typeJsonb(Fluent $column): string
    {
        return 'text';
    }

    /**
     * Append the foreign keys to the table definition.
     *
     * @param array $foreignKeys
     */
    protected function addForeignKeys($foreignKeys): ?string
    {
        return collect($foreignKeys)->reduce(function ($sql, $foreign) {
            $sql .= $this->getForeignKey($foreign);

            if (!is_null($foreign->onDelete)) {
                $sql .= " on delete {$foreign->onDelete}";
            }

            if (!is_null($foreign->onUpdate)) {
                $sql .= " on update {$foreign->onUpdate}";
            }

            return $sql;
        }, '');
    }

    /**
     * Get the SQL for a foreign key constraint.
     *
     * @param \Illuminate\Support\Fluent $foreign
     *
     * @return string
     */
    protected function getForeignKey($foreign)
    {
        // Wrap the columns and references
        $columns = $this->columnize((array) $foreign->columns);
        $on = $this->wrapTable($foreign->on);
        $references = $this->columnize((array) $foreign->references);

        return ", foreign key({$columns}) references {$on}({$references})";
    }
}
