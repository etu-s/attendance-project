<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Models\EmployeeAssignment;
use App\Modules\Organization\Models\GroupMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Wraps ['assignments' => Collection, 'memberships' => Collection].
 *
 * @property array{assignments: Collection<int, EmployeeAssignment>, memberships: Collection<int, GroupMembership>} $resource
 */
class EmployeeHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'assignments' => $this->resource['assignments']->map(fn (EmployeeAssignment $a) => [
                'id' => $a->id,
                'department' => ['id' => $a->department->id, 'name' => $a->department->name],
                'location' => $a->location ? ['id' => $a->location->id, 'name' => $a->location->name] : null,
                'effective_from' => $a->effective_from->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
            ])->values(),
            'memberships' => $this->resource['memberships']->map(fn (GroupMembership $m) => [
                'id' => $m->id,
                'group' => ['id' => $m->group->id, 'name' => $m->group->name, 'priority' => $m->group->priority],
                'effective_from' => $m->effective_from->toDateString(),
                'effective_to' => $m->effective_to?->toDateString(),
            ])->values(),
        ];
    }
}
