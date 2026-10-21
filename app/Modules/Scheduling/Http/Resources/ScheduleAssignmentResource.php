<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\ScheduleAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `target.name` is attached by the controller (ScheduleTargets::names());
 * null when the target was deleted.
 *
 * @mixin ScheduleAssignment
 */
class ScheduleAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'target' => [
                'type' => $this->target_type->value,
                'id' => $this->target_id,
                'name' => $this->resource->getAttribute('target_name'),
            ],
            'shift' => $this->whenLoaded('shift', fn () => [
                'id' => $this->shift->id,
                'name' => $this->shift->name,
                'code' => $this->shift->code,
                'cycle_unit' => $this->shift->cycle_unit->value,
                'cycle_length' => $this->shift->cycle_length,
            ]),
            'anchor_date' => $this->anchor_date->toDateString(),
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
