<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use Spatie\Permission\PermissionRegistrar;
use Stancl\Tenancy\Events\RevertedToCentralContext;
use Stancl\Tenancy\Events\TenancyBootstrapped;

/**
 * spatie/permission caches every role and permission under one key, and it
 * resolves its cache store through `store()`, which stancl's tenant cache
 * manager does not tag. Without a per-tenant key, tenant B could be served
 * tenant A's cached permission map. Switching the key on every tenancy
 * change (and dropping the in-memory copy) keeps them apart.
 */
final class ScopePermissionCacheToTenant
{
    private static ?string $centralKey = null;

    public function __construct(
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function bootstrapped(TenancyBootstrapped $event): void
    {
        self::$centralKey ??= (string) config('permission.cache.key');

        config(['permission.cache.key' => self::$centralKey.'.tenant.'.$event->tenancy->tenant?->getTenantKey()]);
        $this->registrar->initializeCache();
    }

    public function reverted(RevertedToCentralContext $event): void
    {
        if (self::$centralKey !== null) {
            config(['permission.cache.key' => self::$centralKey]);
        }

        $this->registrar->initializeCache();
    }
}
