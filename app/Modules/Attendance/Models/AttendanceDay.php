<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Calculated attendance for one employee on one work date. Derived data:
 * AttendanceCalculator rebuilds it from raw punches, approved corrections and
 * the resolved schedule, so it has no create/update endpoints and is never
 * deleted (there is nothing a delete could lose that recalculation cannot
 * rebuild). Changes go through corrections or schedule changes instead.
 *
 * @property int $id
 * @property int $employee_id
 * @property CarbonImmutable $work_date
 * @property AttendanceStatus $status
 * @property int|null $department_id
 * @property int|null $location_id
 * @property int|null $shift_id
 * @property string|null $schedule_source
 * @property string $timezone
 * @property string|null $holiday_name
 * @property string|null $leave_name
 * @property string|null $leave_portion
 * @property CarbonImmutable|null $first_in_at
 * @property CarbonImmutable|null $last_out_at
 * @property int $scheduled_minutes
 * @property int $worked_minutes
 * @property int $late_minutes
 * @property int $early_leave_minutes
 * @property int $unmatched_punches
 * @property list<string>|null $flags
 * @property CarbonImmutable $calculated_at
 * @property-read Employee $employee
 * @property-read Collection<int, AttendancePeriod> $periods
 * @property-read Collection<int, AttendancePunch> $punches
 */
class AttendanceDay extends Model
{
    /** UTC instants, stored as text so ordering and indexes work (CLAUDE.md). */
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'work_date' => LocalDate::class,
            'status' => AttendanceStatus::class,
            'department_id' => 'integer',
            'location_id' => 'integer',
            'shift_id' => 'integer',
            'first_in_at' => 'immutable_datetime',
            'last_out_at' => 'immutable_datetime',
            'scheduled_minutes' => 'integer',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'unmatched_punches' => 'integer',
            'flags' => 'array',
            'calculated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * @return HasMany<AttendancePeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(AttendancePeriod::class)->orderBy('position');
    }

    /**
     * @return HasMany<AttendancePunch, $this>
     */
    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class)->orderBy('punched_at')->orderBy('id');
    }
}
