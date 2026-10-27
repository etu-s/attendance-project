<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use App\Modules\Timetable\Support\ScheduledPeriod;
use Carbon\CarbonImmutable;

/**
 * How one scheduled period was worked.
 */
final readonly class PeriodResult
{
    public function __construct(
        public int $index,
        public ScheduledPeriod $period,
        public ?CarbonImmutable $in,
        public ?CarbonImmutable $out,
        public int $workedMinutes,
        public int $breakMinutes,
        public int $lateMinutes,
        public int $earlyLeaveMinutes,
        /** Inside a half-day leave: not required. */
        public bool $excused = false,
    ) {}

    public function isMissing(): bool
    {
        return $this->in === null && $this->out === null;
    }

    public function isIncomplete(): bool
    {
        return ($this->in === null) !== ($this->out === null);
    }
}
