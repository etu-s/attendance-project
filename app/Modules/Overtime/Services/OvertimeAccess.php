<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\EmployeeVisibility;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRecord;

/**
 * Who may decide overtime (approved 2026-10-27): overtime.approve for an
 * employee one can see, never about oneself and never an entry one made.
 * Manual entries made by someone who sees everyone and may approve (HR,
 * Tenant Admin) for someone else are approved at once. Registered scoped:
 * visibility is memoized per request / job.
 */
final class OvertimeAccess
{
    /** @var array<string, bool> */
    private array $seen = [];

    public function __construct(
        private readonly EmployeeVisibility $visibility,
    ) {}

    public function canDecide(User $actor, OvertimeRecord $record): bool
    {
        return $record->status === OvertimeStatus::Pending
            && $actor->can('overtime.approve')
            && ! $this->isOwn($actor, $record)
            && $this->canSee($actor, $record->employee);
    }

    public function autoApproves(User $actor, Employee $employee): bool
    {
        return $actor->can('overtime.approve') && $this->visibility->seesEveryone($actor) && $employee->user_id !== $actor->id;
    }

    public function isOwn(User $actor, OvertimeRecord $record): bool
    {
        return $record->employee->user_id === $actor->id || $record->created_by === $actor->id;
    }

    private function canSee(User $actor, Employee $employee): bool
    {
        return $this->seen[$actor->id.':'.$employee->id] ??= $this->visibility->canSee($actor, $employee);
    }
}
