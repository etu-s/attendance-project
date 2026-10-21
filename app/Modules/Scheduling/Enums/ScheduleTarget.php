<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Enums;

/**
 * Who a schedule assignment applies to. Precedence when resolving an
 * employee's day (highest first): temporary > Employee > Group > Department >
 * Organization.
 */
enum ScheduleTarget: string
{
    case Organization = 'organization';
    case Department = 'department';
    case Group = 'group';
    case Employee = 'employee';
}
