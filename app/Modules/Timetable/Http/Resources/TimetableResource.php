<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Http\Resources;

use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Models\TimetableBreak;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Times are whole minutes from local midnight; `end_minute` above 1440 means
 * the next day. Null grace/window values use the workspace default (see the
 * `effective` block on the single-timetable endpoints).
 *
 * @mixin Timetable
 */
class TimetableResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type->value,
            'color' => $this->color,
            'start_minute' => $this->start_minute,
            'duration_minutes' => $this->duration_minutes,
            'end_minute' => $this->endMinute(),
            'crosses_midnight' => $this->crossesMidnight(),
            'required_minutes' => $this->required_minutes,
            'core_start_offset' => $this->core_start_offset,
            'core_duration_minutes' => $this->core_duration_minutes,
            'late_grace_minutes' => $this->late_grace_minutes,
            'early_leave_grace_minutes' => $this->early_leave_grace_minutes,
            'check_in_before_minutes' => $this->check_in_before_minutes,
            'check_out_after_minutes' => $this->check_out_after_minutes,
            'min_work_minutes' => $this->min_work_minutes,
            'work_minutes' => $this->whenLoaded('breaks', fn () => $this->workMinutes()),
            'is_active' => $this->is_active,
            'breaks' => $this->whenLoaded('breaks', fn () => $this->breaks->map(fn (TimetableBreak $b) => $b->snapshot())->values()),
            'shifts_count' => $this->whenCounted('shifts'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
