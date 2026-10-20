<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Events\TenantDatabaseProvisioned;
use App\Modules\Core\Events\TenantDefaultsSyncRequested;
use App\Modules\Core\Permissions\PermissionCatalog;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Identity\Listeners\CreateTenantAdmin;
use App\Modules\Identity\Listeners\ScopePermissionCacheToTenant;
use App\Modules\Identity\Listeners\SyncRolesAndPermissions;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\UserPolicy;
use App\Modules\Identity\Services\PermissionSynchronizer;
use App\Modules\Identity\Services\UserService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Stancl\Tenancy\Events\RevertedToCentralContext;
use Stancl\Tenancy\Events\TenancyBootstrapped;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UserService::class);
        $this->app->singleton(PermissionSynchronizer::class);
    }

    public function boot(): void
    {
        $this->bootModule();

        Relation::enforceMorphMap([
            'user' => User::class,
            'role' => Role::class,
            'permission' => Permission::class,
        ]);

        Gate::policy(User::class, UserPolicy::class);

        $catalog = $this->app->make(PermissionCatalog::class);
        $catalog->register('users.view', [RoleName::HrManager]);
        $catalog->register('users.create', [RoleName::HrManager]);
        $catalog->register('users.update', [RoleName::HrManager]);
        $catalog->register('users.delete');

        Event::listen(TenantDefaultsSyncRequested::class, SyncRolesAndPermissions::class);
        Event::listen(TenantDatabaseProvisioned::class, CreateTenantAdmin::class);
        Event::listen(TenancyBootstrapped::class, [ScopePermissionCacheToTenant::class, 'bootstrapped']);
        Event::listen(RevertedToCentralContext::class, [ScopePermissionCacheToTenant::class, 'reverted']);
    }
}
