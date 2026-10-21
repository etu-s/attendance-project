<?php

declare(strict_types=1);

namespace App\Modules\Organization\Enums;

enum EmployeeStatus: string
{
    case Active = 'active';
    /** On hold (e.g. long absence): keeps assignments and counts toward the plan limit. */
    case Inactive = 'inactive';
    /** Left the organization; set only via termination, not a plain update. */
    case Terminated = 'terminated';

    /**
     * Statuses that occupy a seat in the plan's max_employees.
     *
     * @return list<self>
     */
    public static function countedTowardPlan(): array
    {
        return [self::Active, self::Inactive];
    }
}
