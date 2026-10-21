<?php

declare(strict_types=1);

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only.
 *
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => ucfirst(fake()->unique()->word()).' Department',
            'code' => 'D'.fake()->unique()->numberBetween(100, 99999),
            'is_active' => true,
        ];
    }

    public function childOf(Department $parent): static
    {
        return $this->state(['parent_id' => $parent->id]);
    }
}
