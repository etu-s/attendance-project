<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Database\Factories;

use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Scheduling\Enums\ScheduleTarget;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Timetable\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only. Default: the organization default schedule from
 * 2024-01-01, open-ended. Use the target states to aim it elsewhere.
 *
 * @extends Factory<ScheduleAssignment>
 */
class ScheduleAssignmentFactory extends Factory
{
    protected $model = ScheduleAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target_type' => ScheduleTarget::Organization,
            'target_id' => null,
            'shift_id' => Shift::factory(),
            'anchor_date' => '2024-01-01',
            'effective_from' => '2024-01-01',
            'effective_to' => null,
        ];
    }

    public function forDepartment(Department $department): static
    {
        return $this->state(['target_type' => ScheduleTarget::Department, 'target_id' => $department->id]);
    }

    public function forGroup(Group $group): static
    {
        return $this->state(['target_type' => ScheduleTarget::Group, 'target_id' => $group->id]);
    }

    public function forEmployee(Employee $employee): static
    {
        return $this->state(['target_type' => ScheduleTarget::Employee, 'target_id' => $employee->id]);
    }

    public function between(string $from, ?string $to = null): static
    {
        return $this->state(['effective_from' => $from, 'anchor_date' => $from, 'effective_to' => $to]);
    }
}
