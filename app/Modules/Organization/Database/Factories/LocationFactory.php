<?php

declare(strict_types=1);

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only.
 *
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->city().' Office',
            'code' => 'LOC'.fake()->unique()->numberBetween(100, 99999),
            'address' => fake()->address(),
            'timezone' => null,
            'is_active' => true,
        ];
    }

    public function inTimezone(string $timezone): static
    {
        return $this->state(['timezone' => $timezone]);
    }
}
