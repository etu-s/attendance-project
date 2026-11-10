<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Attendance\Services\AttendanceQuery;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Reports\Models\AttendanceDailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Keeps attendance_daily_stats equal to the aggregates of calculated
 * attendance. A date is always rebuilt whole (delete + insert of its rows),
 * so the result does not depend on what changed or in which order, and
 * running it again is harmless.
 */
final class DailyStatsBuilder
{
    /** Dates rebuilt per transaction, to keep each write short. */
    private const CHUNK_DAYS = 31;

    public function __construct(
        private readonly AttendanceQuery $attendance,
    ) {}

    /**
     * @param  list<string>  $dates  Y-m-d
     */
    public function refresh(array $dates): void
    {
        $dates = array_values(array_unique($dates));
        sort($dates);

        foreach (array_chunk($dates, self::CHUNK_DAYS) as $chunk) {
            // Read and write in one (IMMEDIATE) transaction so a concurrent
            // attendance write cannot slip between the two.
            DB::transaction(function () use ($chunk): void {
                $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
                $rows = array_map(fn (array $row): array => [
                    ...$row,
                    'department_id' => $row['department_id'] ?? 0,
                    'location_id' => $row['location_id'] ?? 0,
                    'refreshed_at' => $now,
                ], $this->attendance->dailyStats($chunk));

                AttendanceDailyStat::query()->whereIn('work_date', $chunk)->delete();
                foreach (array_chunk($rows, 200) as $insert) {
                    AttendanceDailyStat::query()->insert($insert);
                }
            });
        }
    }

    /**
     * Rebuilds every date in [from, to]; dates without attendance end up
     * without rows. Returns the number of dates.
     */
    public function rebuild(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $dates = [];
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $dates[] = $date->format(LocalDate::FORMAT);
        }

        $this->refresh($dates);

        return count($dates);
    }
}
