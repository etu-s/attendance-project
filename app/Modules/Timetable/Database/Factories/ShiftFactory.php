<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Database\Factories;

use App\Modules\Timetable\Enums\CycleUnit;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\ShiftDay;
use App\Modules\Timetable\Models\Timetable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only. Default: a one-week cycle with no working days.
 *
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Shift '.fake()->unique()->numberBetween(1, 99999),
            'code' => 'S'.fake()->unique()->numberBetween(100, 9999999),
            'cycle_unit' => CycleUnit::Week,
            'cycle_length' => 1,
            'is_active' => true,
        ];
    }

    public function rotating(int $days): static
    {
        return $this->state(['cycle_unit' => CycleUnit::Day, 'cycle_length' => $days]);
    }

    /**
     * Puts $timetable on the given cycle day indexes.
     *
     * @param  list<int>  $dayIndexes
     */
    public function on(Timetable $timetable, array $dayIndexes): static
    {
        return $this->afterCreating(function (Shift $shift) use ($timetable, $dayIndexes): void {
            foreach ($dayIndexes as $index) {
                ShiftDay::query()->create(['shift_id' => $shift->id, 'day_index' => $index, 'timetable_id' => $timetable->id]);
            }
        });
    }
}
