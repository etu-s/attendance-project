<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\TemporarySchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TemporarySchedule
 */
class TemporaryScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'employee_code' => $this->employee->employee_code,
                'full_name' => $this->employee->fullName(),
            ]),
            'date_from' => $this->date_from->toDateString(),
            'date_to' => $this->date_to->toDateString(),
            'day_off' => $this->isDayOff(),
            'shift' => $this->shift ? ['id' => $this->shift->id, 'name' => $this->shift->name, 'code' => $this->shift->code] : null,
            'anchor_date' => $this->anchor_date?->toDateString(),
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
