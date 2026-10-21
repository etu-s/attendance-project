<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One scheduled timetable of an attendance day and how it was worked.
 *
 * @property int $id
 * @property int $attendance_day_id
 * @property int $position
 * @property int|null $timetable_id
 * @property string $timetable_name
 * @property bool $flexible
 * @property CarbonImmutable $scheduled_start_at
 * @property CarbonImmutable $scheduled_end_at
 * @property CarbonImmutable|null $in_at
 * @property CarbonImmutable|null $out_at
 * @property int $worked_minutes
 * @property int $break_minutes
 * @property int $late_minutes
 * @property int $early_leave_minutes
 */
class AttendancePeriod extends Model
{
    /** UTC instants, stored as text so ordering and indexes work (CLAUDE.md). */
    protected $dateFormat = 'Y-m-d H:i:s';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attendance_day_id' => 'integer',
            'position' => 'integer',
            'timetable_id' => 'integer',
            'flexible' => 'boolean',
            'scheduled_start_at' => 'immutable_datetime',
            'scheduled_end_at' => 'immutable_datetime',
            'in_at' => 'immutable_datetime',
            'out_at' => 'immutable_datetime',
            'worked_minutes' => 'integer',
            'break_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
        ];
    }
}
