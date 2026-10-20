<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Permissions\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Makes the current tenant's roles and permissions match PermissionCatalog
 * exactly. Idempotent; runs at provisioning and on `php artisan tenants:seed`.
 */
final class PermissionSynchronizer
{
    private const GUARD = 'web';

    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly PermissionRegistrar $registrar,
    ) {}

    public function sync(): void
    {
        DB::transaction(function (): void {
            $names = $this->catalog->permissions();

            foreach ($names as $name) {
                Permission::findOrCreate($name, self::GUARD);
            }

            // Permissions removed from the catalog must not linger as grantable abilities.
            Permission::query()->where('guard_name', self::GUARD)->whereNotIn('name', $names)->delete();

            foreach (RoleName::cases() as $roleName) {
                Role::findOrCreate($roleName->value, self::GUARD)
                    ->syncPermissions($this->catalog->permissionsFor($roleName));
            }
        });

        $this->registrar->forgetCachedPermissions();
    }
}
