<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\DayPortion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One counted (scheduled working) day of a leave request.
 *
 * @property int $id
 * @property int $leave_request_id
 * @property int $employee_id
 * @property CarbonImmutable $date
 * @property DayPortion $portion
 * @property int $leave_year
 */
class LeaveRequestDay extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'leave_request_id' => 'integer',
            'employee_id' => 'integer',
            'date' => LocalDate::class,
            'portion' => DayPortion::class,
            'leave_year' => 'integer',
        ];
    }
}
