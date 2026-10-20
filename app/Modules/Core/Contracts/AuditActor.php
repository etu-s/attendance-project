<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Enums\ActorType;

/**
 * Implemented by authenticatable models so Core can attribute audit entries
 * without depending on the modules that own those models.
 */
interface AuditActor
{
    public function auditActorType(): ActorType;

    public function auditActorId(): int;

    public function auditActorName(): string;
}
