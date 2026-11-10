<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Listeners;

use App\Modules\Attendance\Jobs\RecalculateAttendance;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Organization\Services\AssignmentService;
use App\Modules\Organization\Services\DepartmentTree;
use App\Modules\Organization\Services\MembershipService;
use App\Modules\Scheduling\Events\ScheduleChanged;

/**
 * A schedule, temporary schedule or holiday changed: recalculate the days
 * that already happened, for the people the change can reach. Future days
 * have no attendance yet, and an open-ended change ends today.
 *
 *  - employee: that employee;
 *  - department: everyone assigned to it or a sub-department (they inherit
 *    its schedule) at any time in the range;
 *  - group: everyone who was a member in the range;
 *  - location (location holiday): everyone assigned to that location;
 *  - organization (default schedule, workspace holiday): everyone.
 *
 * The lists include history, so a transfer inside the range is covered. The
 * calculator skips days whose result did not change.
 */
final class RecalculateOnScheduleChange
{
    public function __construct(
        private readonly TenantClock $clock,
        private readonly AssignmentService $assignments,
        private readonly MembershipService $memberships,
        private readonly DepartmentTree $tree,
    ) {}

    public function handle(ScheduleChanged $event): void
    {
        $today = $this->clock->today();
        $from = LocalDate::parse($event->from);
        $to = $event->to === null ? $today : LocalDate::parse($event->to)->min($today);

        if ($from->greaterThan($today)) {
            return;
        }

        $employeeIds = match ($event->scopeId === null ? 'organization' : $event->scopeType) {
            'employee' => [(int) $event->scopeId],
            'department' => $this->assignments->employeeIdsIn($this->tree->withDescendants([(int) $event->scopeId]), null, $from, $to),
            'group' => $this->memberships->employeeIdsIn((int) $event->scopeId, $from, $to),
            'location' => $this->assignments->employeeIdsIn(null, (int) $event->scopeId, $from, $to),
            default => null,
        };

        if ($employeeIds === []) {
            return;
        }

        RecalculateAttendance::dispatch($employeeIds, $from->format(LocalDate::FORMAT), $to->format(LocalDate::FORMAT));
    }
}
