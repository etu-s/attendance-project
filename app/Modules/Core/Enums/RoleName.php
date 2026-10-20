<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/**
 * Tenant roles (Super Admin is a separate central account, not a role).
 * Roles are platform-defined; their permissions come from PermissionCatalog.
 */
enum RoleName: string
{
    case TenantAdmin = 'tenant-admin';
    case HrManager = 'hr-manager';
    case DepartmentManager = 'department-manager';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::TenantAdmin => 'Tenant Admin',
            self::HrManager => 'HR Manager',
            self::DepartmentManager => 'Department Manager',
            self::Employee => 'Employee',
        };
    }
}
