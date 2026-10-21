<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Database\Factories;

use App\Modules\Timetable\Enums\TimetableType;
use App\Modules\Timetable\Models\Timetable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only. Default: fixed 09:00–18:00 with workspace-default grace.
 *
 * @extends Factory<Timetable>
 */
class TimetableFactory extends Factory
{
    protected $model = Timetable::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Day '.fake()->unique()->numberBetween(1, 99999),
            'code' => 'T'.fake()->unique()->numberBetween(100, 9999999),
            'type' => TimetableType::Fixed,
            'color' => null,
            'start_minute' => 9 * 60,
            'duration_minutes' => 9 * 60,
            'is_active' => true,
        ];
    }

    /** Fixed timetable from HH:MM to HH:MM (end at or before start = next day). */
    public function between(string $start, string $end): static
    {
        $from = self::minutes($start);
        $to = self::minutes($end);

        return $this->state(['start_minute' => $from, 'duration_minutes' => (($to - $from + 1440) % 1440) ?: 1440]);
    }

    public function flexible(string $windowStart, string $windowEnd, int $requiredMinutes): static
    {
        return $this->between($windowStart, $windowEnd)->state([
            'type' => TimetableType::Flexible,
            'required_minutes' => $requiredMinutes,
        ]);
    }

    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}
