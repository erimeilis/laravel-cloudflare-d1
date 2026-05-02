<?php

namespace EriMeilis\CloudflareD1\Database\Schema;

use Illuminate\Database\Schema\SQLiteBuilder;

class D1SchemaBuilder extends SQLiteBuilder
{
    /**
     * D1 is a remote database, not a file — override file-based operations.
     */
    public function createDatabase($name): bool
    {
        throw new \LogicException('D1 databases are managed via the Cloudflare dashboard, not created via SQL.');
    }

    /**
     * D1 databases cannot be dropped via SQL.
     */
    public function dropDatabaseIfExists($name): bool
    {
        throw new \LogicException('D1 databases are managed via the Cloudflare dashboard, not dropped via SQL.');
    }

    /**
     * D1 has no database file to refresh.
     */
    public function refreshDatabaseFile($path = null): void
    {
        // No-op: D1 is not file-based
    }
}
