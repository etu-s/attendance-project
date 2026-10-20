<?php

declare(strict_types=1);

use App\Modules\Central\Models\Domain;
use App\Modules\Central\Models\Tenant;
use App\Modules\Core\Tenancy\TenantSqliteDatabaseManager;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\UUIDGenerator;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => UUIDGenerator::class,

    'domain_model' => Domain::class,

    /*
     * Hosts that serve the central (super admin) API. Tenants live on
     * subdomains of these hosts, e.g. `acme.localhost` in local dev.
     */
    'central_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CENTRAL_DOMAINS', 'localhost,127.0.0.1')),
    ))),

    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        CacheTenancyBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
    ],

    'database' => [
        'central_connection' => env('DB_CONNECTION', 'central'),
        'template_tenant_connection' => 'tenant_template',

        // One SQLite file per tenant: <sqlite_directory>/<tenant uuid>.sqlite
        'prefix' => '',
        'suffix' => '.sqlite',
        'sqlite_directory' => env('TENANT_DB_DIRECTORY', database_path('tenants')),

        'managers' => [
            'sqlite' => TenantSqliteDatabaseManager::class,
        ],
    ],

    // Cache calls made in tenant context are tagged with this base + tenant id,
    // so the cache store must support tags (redis or array).
    'cache' => [
        'tag_base' => 'tenant',
    ],

    'filesystem' => [
        'suffix_base' => 'tenant',
        'disks' => [
            'local',
            'public',
        ],
        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
        'suffix_storage_path' => true,
        'asset_helper_tenancy' => false,
    ],

    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],

    'features' => [],

    // Routes are registered per module (see ModuleServiceProvider), not from routes/tenant.php.
    'routes' => false,

    /*
     * `--path` is filled at boot with every module's tenant migration folder
     * (see ModuleServiceProvider::registerTenantMigrations).
     */
    'migration_parameters' => [
        '--force' => true,
        '--path' => [],
        '--realpath' => true,
    ],

    'seeder_parameters' => [
        '--class' => 'Database\\Seeders\\TenantDatabaseSeeder',
    ],
];
