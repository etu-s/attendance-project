<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Scheduling\Support\ResolvedDay;
use Carbon\CarbonImmutable;

/**
 * The calculated attendance of one employee on one work date, before it is
 * written to attendance_days / attendance_periods / attendance_punches.
 */
final readonly class DayResult
{
    /**
     * @param  list<PeriodResult>  $periods
     * @param  list<PunchUse>  $punches
     * @param  list<string>  $flags
     */
    public function __construct(
        public ResolvedDay $day,
        public AttendanceStatus $status,
        public array $periods,
        public array $punches,
        public ?CarbonImmutable $firstIn,
        public ?CarbonImmutable $lastOut,
        public int $scheduledMinutes,
        public int $workedMinutes,
        public int $lateMinutes,
        public int $earlyLeaveMinutes,
        public int $unmatchedPunches,
        public array $flags,
    ) {}

    public function hasPunches(): bool
    {
        return $this->punches !== [];
    }
}
