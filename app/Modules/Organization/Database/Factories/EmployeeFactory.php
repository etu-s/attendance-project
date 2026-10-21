<?php

declare(strict_types=1);

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Enums\EmployeeStatus;
use App\Modules\Organization\Enums\EmploymentType;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\EmployeeAssignment;
use App\Modules\Organization\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only. Use inDepartment() for a complete employee: it adds the
 * open-ended assignment from the hire date that EmployeeService::create makes.
 *
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_code' => 'E'.fake()->unique()->numberBetween(1000, 9999999),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'job_title' => fake()->jobTitle(),
            'employment_type' => EmploymentType::FullTime,
            'status' => EmployeeStatus::Active,
            'hire_date' => '2024-01-01',
            'termination_date' => null,
            'user_id' => null,
        ];
    }

    public function inDepartment(Department $department, ?Location $location = null): static
    {
        return $this->afterCreating(function (Employee $employee) use ($department, $location): void {
            EmployeeAssignment::query()->create([
                'employee_id' => $employee->id,
                'department_id' => $department->id,
                'location_id' => $location?->id,
                'effective_from' => $employee->hire_date,
                'effective_to' => $employee->termination_date,
            ]);
        });
    }

    public function terminated(string $on): static
    {
        return $this->state(['status' => EmployeeStatus::Terminated, 'termination_date' => $on]);
    }
}
