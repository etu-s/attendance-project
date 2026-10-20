<?php

use Illuminate\Support\Str;

/*
 * SQLite is the only supported engine (see CLAUDE.md). Every connection gets
 * the same pragmas: WAL for concurrent readers, enforced foreign keys, a busy
 * timeout so short write contention waits instead of failing, and NORMAL
 * synchronous, which is durable enough under WAL and much faster than FULL.
 */
$sqlitePragmas = [
    'foreign_key_constraints' => true,
    'busy_timeout' => (int) env('DB_BUSY_TIMEOUT', 5000),
    'journal_mode' => 'wal',
    'synchronous' => 'normal',
    'transaction_mode' => 'IMMEDIATE',
];

return [

    'default' => env('DB_CONNECTION', 'central'),

    'connections' => [

        'central' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('central.sqlite')),
            'prefix' => '',
            ...$sqlitePragmas,
        ],

        // Template for tenant connections. stancl/tenancy copies it and fills in
        // `database` with the tenant's file path (see TenantSqliteDatabaseManager).
        'tenant_template' => [
            'driver' => 'sqlite',
            'database' => null,
            'prefix' => '',
            ...$sqlitePragmas,
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
