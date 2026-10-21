<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Http\Resources;

use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\ShiftDay;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * `days` lists only working days of the cycle (index 0 = first day; for week
 * cycles index 0 is a Monday). Missing indexes are rest days.
 *
 * @mixin Shift
 */
class ShiftResource extends JsonResource
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
            'cycle_unit' => $this->cycle_unit->value,
            'cycle_length' => $this->cycle_length,
            'cycle_days' => $this->cycleDays(),
            'description' => $this->description,
            'is_active' => $this->is_active,
            'days' => $this->whenLoaded('days', fn () => $this->days
                ->groupBy('day_index')
                ->map(fn (Collection $day, int $index) => [
                    'day_index' => $index,
                    'timetables' => $day->map(fn (ShiftDay $d) => [
                        'id' => $d->timetable->id,
                        'name' => $d->timetable->name,
                        'code' => $d->timetable->code,
                        'color' => $d->timetable->color,
                        'type' => $d->timetable->type->value,
                        'start_minute' => $d->timetable->start_minute,
                        'duration_minutes' => $d->timetable->duration_minutes,
                    ])->sortBy('start_minute')->values(),
                ])
                ->values()),
            'working_days' => $this->whenLoaded('days', fn () => $this->days->pluck('day_index')->unique()->count()),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
