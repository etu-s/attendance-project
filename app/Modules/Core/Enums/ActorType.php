<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

enum ActorType: string
{
    case User = 'user';
    case SuperAdmin = 'super_admin';
    case System = 'system';
}
