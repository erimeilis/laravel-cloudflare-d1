<?php

namespace EriMeilis\CloudflareD1\Database\Query;

use Illuminate\Database\Query\Grammars\SQLiteGrammar;

class D1QueryGrammar extends SQLiteGrammar
{
    /**
     * D1 uses SQLite syntax, so we extend SQLite grammar
     * This provides automatic compatibility with SQLite query patterns.
     *
     * Any D1-specific customizations can be added here
     */

    /**
     * Compile an insert statement into SQL.
     */
    public function compileInsert(\Illuminate\Database\Query\Builder $query, array $values): string
    {
        // D1 supports standard SQLite INSERT syntax
        return parent::compileInsert($query, $values);
    }

    /**
     * Compile an update statement into SQL.
     */
    public function compileUpdate(\Illuminate\Database\Query\Builder $query, array $values): string
    {
        // D1 supports standard SQLite UPDATE syntax
        return parent::compileUpdate($query, $values);
    }

    /**
     * Compile a delete statement into SQL.
     */
    public function compileDelete(\Illuminate\Database\Query\Builder $query): string
    {
        // D1 supports standard SQLite DELETE syntax
        return parent::compileDelete($query);
    }

    /**
     * Wrap a value in keyword identifiers.
     *
     * @param \Illuminate\Database\Query\Expression|string $value
     */
    protected function wrapValue($value): string
    {
        if ($value === '*') {
            return $value;
        }

        // SQLite uses double quotes for identifiers
        return '"'.str_replace('"', '""', $value).'"';
    }
}
