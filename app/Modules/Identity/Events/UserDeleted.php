<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

/**
 * Fired synchronously, inside the delete transaction, when a tenant user is
 * deleted. Modules that reference users (employee links, managers) clean up.
 */
final readonly class UserDeleted
{
    public function __construct(
        public int $userId,
    ) {}
}
