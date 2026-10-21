<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendancePeriod;
use App\Modules\Attendance\Models\AttendancePunch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Instants are UTC (ISO 8601, Z); `timezone` is the day's timezone for
 * display. Periods and punches are included on the detail endpoint.
 *
 * @mixin AttendanceDay
 */
class AttendanceDayResource extends JsonResource
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
                'full_name' => $this->employee->fullName(),
                'employee_code' => $this->employee->employee_code,
            ]),
            'work_date' => $this->work_date->format('Y-m-d'),
            'status' => $this->status->value,
            'timezone' => $this->timezone,
            'holiday_name' => $this->holiday_name,
            'schedule_source' => $this->schedule_source,
            'shift_id' => $this->shift_id,
            'department_id' => $this->department_id,
            'location_id' => $this->location_id,
            'first_in_at' => $this->first_in_at?->toIso8601ZuluString(),
            'last_out_at' => $this->last_out_at?->toIso8601ZuluString(),
            'scheduled_minutes' => $this->scheduled_minutes,
            'worked_minutes' => $this->worked_minutes,
            'late_minutes' => $this->late_minutes,
            'early_leave_minutes' => $this->early_leave_minutes,
            'unmatched_punches' => $this->unmatched_punches,
            'flags' => $this->flags ?? [],
            'calculated_at' => $this->calculated_at->toIso8601ZuluString(),
            'periods' => $this->whenLoaded('periods', fn () => $this->periods->map(fn (AttendancePeriod $p) => [
                'id' => $p->id,
                'position' => $p->position,
                'timetable_id' => $p->timetable_id,
                'timetable_name' => $p->timetable_name,
                'flexible' => $p->flexible,
                'scheduled_start_at' => $p->scheduled_start_at->toIso8601ZuluString(),
                'scheduled_end_at' => $p->scheduled_end_at->toIso8601ZuluString(),
                'in_at' => $p->in_at?->toIso8601ZuluString(),
                'out_at' => $p->out_at?->toIso8601ZuluString(),
                'worked_minutes' => $p->worked_minutes,
                'break_minutes' => $p->break_minutes,
                'late_minutes' => $p->late_minutes,
                'early_leave_minutes' => $p->early_leave_minutes,
            ])->all()),
            'punches' => $this->whenLoaded('punches', fn () => $this->punches->map(fn (AttendancePunch $p) => [
                'id' => $p->id,
                'raw_punch_id' => $p->raw_punch_id,
                'punch_correction_id' => $p->punch_correction_id,
                'attendance_period_id' => $p->attendance_period_id,
                'punched_at' => $p->punched_at->toIso8601ZuluString(),
                'role' => $p->role->value,
                'source' => $p->rawPunch?->source?->name,
            ])->all()),
        ];
    }
}
