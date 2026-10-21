<?php

declare(strict_types=1);

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only.
 *
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()).' Crew',
            'code' => 'G'.fake()->unique()->numberBetween(100, 99999),
            'priority' => fake()->unique()->numberBetween(1, 100000),
            'description' => null,
            'is_active' => true,
        ];
    }
}
