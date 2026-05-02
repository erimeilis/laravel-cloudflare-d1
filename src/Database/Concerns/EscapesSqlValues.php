<?php

namespace EriMeilis\CloudflareD1\Database\Concerns;

trait EscapesSqlValues
{
    /**
     * Escape a value for use in raw SQL (SQLite-compatible).
     */
    protected function escapeValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return "'".str_replace("'", "''", $value)."'";
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return "'".str_replace("'", "''", (string) $value)."'";
        }

        throw new \InvalidArgumentException(
            'Cannot escape value of type '.get_debug_type($value)
        );
    }
}
