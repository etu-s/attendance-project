<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Concerns\Auditable;
use App\Modules\Organization\Models\Employee;
use App\Modules\Scheduling\Database\Factories\TemporaryScheduleFactory;
use App\Modules\Timetable\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off override for one employee: work shift X (from anchor) or have the
 * days off, from date_from to date_to inclusive. Beats every other schedule
 * and holidays.
 *
 * @property int $id
 * @property int $employee_id
 * @property CarbonImmutable $date_from
 * @property CarbonImmutable $date_to
 * @property int|null $shift_id
 * @property CarbonImmutable|null $anchor_date
 * @property string|null $reason
 * @property-read Shift|null $shift
 * @property-read Employee $employee
 */
class TemporarySchedule extends Model
{
    use Auditable;

    /** @use HasFactory<TemporaryScheduleFactory> */
    use HasFactory;

    protected $fillable = ['employee_id', 'date_from', 'date_to', 'shift_id', 'anchor_date', 'reason'];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'shift_id' => 'integer',
            'date_from' => LocalDate::class,
            'date_to' => LocalDate::class,
            'anchor_date' => LocalDate::class,
        ];
    }

    public function isDayOff(): bool
    {
        return $this->shift_id === null;
    }

    public function covers(CarbonImmutable $date): bool
    {
        return $this->date_from->lessThanOrEqualTo($date) && $this->date_to->greaterThanOrEqualTo($date);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Includes soft-deleted employees so their past overrides still show a name.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    protected static function newFactory(): TemporaryScheduleFactory
    {
        return TemporaryScheduleFactory::new();
    }
}
