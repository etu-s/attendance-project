<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

enum UserStatus: string
{
    case Active = 'active';
    /** Cannot log in; keeps role and history. */
    case Inactive = 'inactive';
}
