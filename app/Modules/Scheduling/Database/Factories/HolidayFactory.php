<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Database\Factories;

use App\Modules\Scheduling\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only. Default: a one-off workspace-wide holiday.
 *
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => '2026-03-23',
            'name' => 'Holiday '.fake()->unique()->numberBetween(1, 99999),
            'location_id' => null,
            'recurs_yearly' => false,
        ];
    }
}
