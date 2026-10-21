<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Database\Seeders;

use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Services\ShiftService;
use App\Modules\Timetable\Services\TimetableService;
use Illuminate\Database\Seeder;

/**
 * Local demo timetables and shifts (run inside tenant context). Skips tenants
 * that already have shifts.
 */
final class DemoTimetableSeeder extends Seeder
{
    public function run(TimetableService $timetables, ShiftService $shifts): void
    {
        if (Shift::query()->exists()) {
            return;
        }

        $day = $timetables->create([
            'name' => 'Office day', 'code' => 'DAY', 'type' => 'fixed', 'color' => '#3b82f6',
            'start_minute' => 9 * 60, 'duration_minutes' => 9 * 60,
            'breaks' => [['kind' => 'fixed', 'start_offset' => 4 * 60, 'duration_minutes' => 60, 'is_paid' => false]],
        ]);
        $night = $timetables->create([
            'name' => 'Night', 'code' => 'NIGHT', 'type' => 'fixed', 'color' => '#8b5cf6',
            'start_minute' => 22 * 60, 'duration_minutes' => 8 * 60, 'late_grace_minutes' => 10,
            'breaks' => [['kind' => 'floating', 'duration_minutes' => 30, 'is_paid' => true]],
        ]);
        $warehouse = $timetables->create([
            'name' => 'Warehouse long day', 'code' => 'WH12', 'type' => 'fixed', 'color' => '#f59e0b',
            'start_minute' => 7 * 60, 'duration_minutes' => 12 * 60,
            'breaks' => [['kind' => 'floating', 'duration_minutes' => 45, 'is_paid' => false]],
        ]);
        $flex = $timetables->create([
            'name' => 'Flexible office', 'code' => 'FLEX', 'type' => 'flexible', 'color' => '#10b981',
            'start_minute' => 7 * 60, 'duration_minutes' => 13 * 60, 'required_minutes' => 8 * 60,
            'core_start_offset' => 3 * 60, 'core_duration_minutes' => 5 * 60,
            'breaks' => [['kind' => 'floating', 'duration_minutes' => 30, 'is_paid' => false]],
        ]);

        $office = $shifts->create(['name' => 'Office week', 'code' => 'OFFICE', 'cycle_unit' => 'week', 'cycle_length' => 1]);
        $shifts->setDays($office, array_map(fn (int $i) => ['day_index' => $i, 'timetable_ids' => [$day->id]], range(0, 4)));

        $flexWeek = $shifts->create(['name' => 'Flexible week', 'code' => 'FLEXWEEK', 'cycle_unit' => 'week', 'cycle_length' => 1]);
        $shifts->setDays($flexWeek, array_map(fn (int $i) => ['day_index' => $i, 'timetable_ids' => [$flex->id]], range(0, 4)));

        $rota = $shifts->create([
            'name' => 'Warehouse 4-on/4-off', 'code' => 'ROTA44', 'cycle_unit' => 'day', 'cycle_length' => 8,
            'description' => 'Four long days, four days off.',
        ]);
        $shifts->setDays($rota, array_map(fn (int $i) => ['day_index' => $i, 'timetable_ids' => [$warehouse->id]], range(0, 3)));

        $nights = $shifts->create(['name' => 'Night crew', 'code' => 'NIGHTS', 'cycle_unit' => 'week', 'cycle_length' => 2]);
        $shifts->setDays($nights, array_map(fn (int $i) => ['day_index' => $i, 'timetable_ids' => [$night->id]], [0, 1, 2, 3, 7, 8, 9]));
    }
}
