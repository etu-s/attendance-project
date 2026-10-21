<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\GroupMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Current department/location/groups are "as of today" in the tenant
 * timezone; the full dated history is under GET employees/{id}/history.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignment = $this->relationLoaded('currentAssignment') ? $this->currentAssignment : null;

        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'employment_type' => $this->employment_type->value,
            'status' => $this->status->value,
            'hire_date' => $this->hire_date->toDateString(),
            'termination_date' => $this->termination_date?->toDateString(),
            'user_id' => $this->user_id,
            'department' => $this->when($this->relationLoaded('currentAssignment'), fn () => $assignment ? [
                'id' => $assignment->department->id,
                'name' => $assignment->department->name,
            ] : null),
            'location' => $this->when($this->relationLoaded('currentAssignment'), fn () => $assignment?->location ? [
                'id' => $assignment->location->id,
                'name' => $assignment->location->name,
                'timezone' => $assignment->location->timezone,
            ] : null),
            'groups' => $this->whenLoaded('currentMemberships', fn () => $this->currentMemberships
                ->sortBy(fn (GroupMembership $m) => $m->group->priority)
                ->map(fn (GroupMembership $m) => [
                    'id' => $m->group->id,
                    'name' => $m->group->name,
                    'priority' => $m->group->priority,
                    'since' => $m->effective_from->toDateString(),
                ])->values()),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
