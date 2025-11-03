<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cloudflare D1 Database Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for Cloudflare D1 database connections.
    | These settings optimize performance and behavior for D1 databases.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Batch Operations
    |--------------------------------------------------------------------------
    |
    | Batch operations send multiple SQL statements in a single API call,
    | reducing latency and improving performance by up to 10x.
    |
    */

    'batch' => [
        // Enable automatic batching within transactions
        'enabled' => env('D1_BATCH_ENABLED', true),

        // Maximum number of queries per batch (1-100)
        // D1 can handle large batches, but smaller batches fail faster
        'size' => env('D1_BATCH_SIZE', 50),

        // Auto-flush batch after this many milliseconds of inactivity
        'window_ms' => env('D1_BATCH_WINDOW', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for D1 API communication.
    |
    */

    'api' => [
        // Use /raw endpoint instead of /query (40-60% faster)
        'use_raw_endpoint' => env('D1_USE_RAW_ENDPOINT', true),

        // API request timeout in seconds
        'timeout' => env('D1_API_TIMEOUT', 30),

        // Connection timeout in seconds
        'connect_timeout' => env('D1_API_CONNECT_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Caching
    |--------------------------------------------------------------------------
    |
    | Cache read query results to reduce API calls.
    | Only recommended for read-heavy workloads with infrequent writes.
    |
    */

    'cache' => [
        // Enable query result caching
        'enabled' => env('D1_CACHE_ENABLED', false),

        // Cache driver (must be configured in config/cache.php)
        'driver' => env('D1_CACHE_DRIVER', 'redis'),

        // Cache TTL in seconds
        'ttl' => env('D1_CACHE_TTL', 300), // 5 minutes

        // Cache key prefix
        'prefix' => env('D1_CACHE_PREFIX', 'd1_cache_'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Foreign Key Constraints
    |--------------------------------------------------------------------------
    |
    | SQLite (and thus D1) has foreign key constraints disabled by default.
    | This package automatically enables them for data integrity.
    |
    */

    'foreign_keys' => [
        // Automatically enable foreign key constraints
        'auto_enable' => env('D1_AUTO_ENABLE_FOREIGN_KEYS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    |
    | Log slow queries and API performance metrics.
    |
    */

    'monitoring' => [
        // Log queries exceeding this threshold (milliseconds)
        'slow_query_threshold' => env('D1_SLOW_QUERY_THRESHOLD', 1000),

        // Log all D1 API requests
        'log_api_requests' => env('D1_LOG_API_REQUESTS', false),
    ],
];
