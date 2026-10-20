<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case RoleChanged = 'role_changed';
}
