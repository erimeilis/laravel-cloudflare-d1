<?php

namespace EriMeilis\CloudflareD1\Database\Query;

use Illuminate\Database\Query\Grammars\SQLiteGrammar;

class D1QueryGrammar extends SQLiteGrammar
{
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
