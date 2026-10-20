<?php

declare(strict_types=1);

namespace App\Modules\Core\Permissions;

use App\Modules\Core\Enums\RoleName;
use InvalidArgumentException;

/**
 * Single source of truth for tenant permissions. Each module registers the
 * permissions it checks and which roles get them by default; Identity syncs
 * this catalog into every tenant's roles/permissions tables.
 *
 * Tenant Admin implicitly holds every permission.
 */
final class PermissionCatalog
{
    /** @var array<string, list<RoleName>> */
    private array $grants = [];

    /**
     * @param  list<RoleName>  $roles  roles (besides Tenant Admin) granted this permission
     */
    public function register(string $permission, array $roles = []): void
    {
        if (! preg_match('/^[a-z][a-z-]*\.[a-z][a-z-]*$/', $permission)) {
            throw new InvalidArgumentException("Permission [{$permission}] must look like `resource.action`.");
        }

        if (isset($this->grants[$permission])) {
            throw new InvalidArgumentException("Permission [{$permission}] is already registered.");
        }

        $this->grants[$permission] = $roles;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_keys($this->grants);
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(RoleName $role): array
    {
        if ($role === RoleName::TenantAdmin) {
            return $this->permissions();
        }

        return array_keys(array_filter(
            $this->grants,
            fn (array $roles) => in_array($role, $roles, true),
        ));
    }
}
