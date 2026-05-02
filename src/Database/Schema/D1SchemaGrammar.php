<?php

namespace EriMeilis\CloudflareD1\Database\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\SQLiteGrammar;
use Illuminate\Support\Fluent;

class D1SchemaGrammar extends SQLiteGrammar
{
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
     * Compile a rename table command.
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        $from = $this->wrapTable($blueprint);

        return "alter table {$from} rename to ".$this->wrapTable($command->to);
    }

    /**
     * Create the column definition for a timestamp type.
     * D1/SQLite stores timestamps as text with optional current datetime default.
     */
    protected function typeTimestamp(Fluent $column): string
    {
        if ($column->useCurrent) {
            return 'text default (datetime(\'now\'))';
        }

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
        $columns = $this->columnize((array) $foreign->columns);
        $on = $this->wrapTable($foreign->on);
        $references = $this->columnize((array) $foreign->references);

        return ", foreign key({$columns}) references {$on}({$references})";
    }
}
