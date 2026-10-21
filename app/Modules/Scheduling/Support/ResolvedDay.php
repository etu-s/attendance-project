<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Support;

use App\Modules\Scheduling\Enums\DayStatus;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Support\ScheduledPeriod;

/**
 * What one employee is scheduled to do on one work date, and why.
 *
 * `source` names the level that decided it: temporary, employee, group,
 * department, organization (null when unscheduled / not employed).
 * `inheritedFrom` is set when a department used its ancestor's schedule.
 */
final readonly class ResolvedDay
{
    /**
     * @param  list<ScheduledPeriod>  $periods
     */
    public function __construct(
        public string $date,
        public DayStatus $status,
        public ?string $source,
        public ?int $sourceId,
        public ?string $sourceName,
        public ?Shift $shift,
        public array $periods,
        public string $timezone,
        public ?string $holiday = null,
        public ?string $inheritedFrom = null,
    ) {}

    public function isWorkingDay(): bool
    {
        return $this->status === DayStatus::Working;
    }
}
