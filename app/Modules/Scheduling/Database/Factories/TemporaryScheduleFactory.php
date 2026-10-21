<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Database\Factories;

use App\Modules\Scheduling\Models\TemporarySchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only. Default: a one-day day off; set employee_id.
 *
 * @extends Factory<TemporarySchedule>
 */
class TemporaryScheduleFactory extends Factory
{
    protected $model = TemporarySchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date_from' => '2026-03-02',
            'date_to' => '2026-03-02',
            'shift_id' => null,
            'anchor_date' => null,
            'reason' => null,
        ];
    }
}
