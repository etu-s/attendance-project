<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for every module in app/Modules/<Name>/.
 *
 * A module is self-contained: it ships central and/or tenant migrations and
 * route files in conventional folders, and this base wires them up so adding
 * a module never requires touching global config.
 *
 *   Database/Migrations/central  -> central DB (php artisan migrate)
 *   Database/Migrations/tenant   -> each tenant DB (php artisan tenants:migrate)
 *   routes/central.php           -> /api/v1/central/*, central domains only
 *   routes/tenant.php            -> /api/v1/*, tenant subdomains only
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    protected function bootModule(): void
    {
        $this->loadCentralMigrations();
        $this->registerTenantMigrations();
        $this->mapRoutes();
    }

    protected function modulePath(string $path = ''): string
    {
        $base = dirname((string) (new ReflectionClass($this))->getFileName());

        return $path === '' ? $base : $base.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function loadCentralMigrations(): void
    {
        $path = $this->modulePath('Database/Migrations/central');

        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    private function registerTenantMigrations(): void
    {
        $path = $this->modulePath('Database/Migrations/tenant');

        if (! is_dir($path)) {
            return;
        }

        $paths = config('tenancy.migration_parameters.--path', []);

        if (! in_array($path, $paths, true)) {
            $paths[] = $path;
            config(['tenancy.migration_parameters.--path' => $paths]);
        }
    }

    private function mapRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $central = $this->modulePath('routes/central.php');

        if (is_file($central)) {
            Route::prefix('api/v1/central')
                ->middleware(['api', 'central'])
                ->name('central.')
                ->group($central);
        }

        $tenant = $this->modulePath('routes/tenant.php');

        if (is_file($tenant)) {
            Route::prefix('api/v1')
                ->middleware(['api', 'tenant'])
                ->name('tenant.')
                ->group($tenant);
        }
    }
}
