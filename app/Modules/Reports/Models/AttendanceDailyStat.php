<?php

declare(strict_types=1);

namespace App\Modules\Reports\Models;

use App\Modules\Core\Casts\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Rollup of calculated attendance for one (work date, department, location);
 * 0 means none. Written only by DailyStatsBuilder; the columns are the
 * aliases of AttendanceQuery::aggregateColumns().
 *
 * @property int $id
 * @property CarbonImmutable $work_date
 * @property int $department_id
 * @property int $location_id
 * @property int $days
 * @property CarbonImmutable $refreshed_at
 */
class AttendanceDailyStat extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_date' => LocalDate::class,
            'department_id' => 'integer',
            'location_id' => 'integer',
            'days' => 'integer',
            'refreshed_at' => 'immutable_datetime',
        ];
    }
}
