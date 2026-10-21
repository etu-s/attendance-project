<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Support;

use App\Modules\Timetable\Models\Timetable;
use Carbon\CarbonImmutable;

/**
 * One timetable applied to one work date, with real instants (UTC).
 *
 * For a flexible timetable, startsAt/endsAt bound the window. `minutes()` is
 * the real elapsed time, which differs from the nominal duration on DST days.
 */
final readonly class ScheduledPeriod
{
    public function __construct(
        public string $workDate,
        public Timetable $timetable,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    public function minutes(): int
    {
        return (int) $this->startsAt->diffInMinutes($this->endsAt);
    }
}
