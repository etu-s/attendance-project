<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Models;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Concerns\Auditable;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Overtime\Database\Factories\OvertimeRecordFactory;
use App\Modules\Overtime\Enums\OvertimeKind;
use App\Modules\Overtime\Enums\OvertimeSource;
use App\Modules\Overtime\Enums\OvertimeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Overtime of one employee on one work date (calculated or manual). Audited:
 * the log keeps every earlier decision when a recalculation reopens it.
 *
 * @property int $id
 * @property int $employee_id
 * @property CarbonImmutable $work_date
 * @property OvertimeSource $source
 * @property int $before_minutes
 * @property int $after_minutes
 * @property int $rest_day_minutes
 * @property int $holiday_minutes
 * @property int $calculated_minutes
 * @property int $rate_workday
 * @property int $rate_rest_day
 * @property int $rate_holiday
 * @property int $weighted_minutes
 * @property OvertimeStatus $status
 * @property int|null $approved_minutes
 * @property int|null $approved_weighted_minutes
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property bool $changed_after_decision
 * @property string|null $reason
 * @property int|null $created_by
 * @property-read Employee $employee
 * @property-read User|null $decider
 * @property-read User|null $creator
 */
class OvertimeRecord extends Model
{
    use Auditable, SoftDeletes;

    /** @use HasFactory<OvertimeRecordFactory> */
    use HasFactory;

    /** UTC instants, stored as text so ordering and indexes work (CLAUDE.md). */
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'work_date' => LocalDate::class,
            'source' => OvertimeSource::class,
            'before_minutes' => 'integer',
            'after_minutes' => 'integer',
            'rest_day_minutes' => 'integer',
            'holiday_minutes' => 'integer',
            'calculated_minutes' => 'integer',
            'rate_workday' => 'integer',
            'rate_rest_day' => 'integer',
            'rate_holiday' => 'integer',
            'weighted_minutes' => 'integer',
            'status' => OvertimeStatus::class,
            'approved_minutes' => 'integer',
            'approved_weighted_minutes' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'immutable_datetime',
            'changed_after_decision' => 'boolean',
            'created_by' => 'integer',
        ];
    }

    /**
     * The rate that applies to most of the minutes (for display).
     */
    public function mainKind(): OvertimeKind
    {
        return match (true) {
            $this->holiday_minutes > 0 => OvertimeKind::Holiday,
            $this->rest_day_minutes > 0 => OvertimeKind::RestDay,
            default => OvertimeKind::Workday,
        };
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    protected static function newFactory(): OvertimeRecordFactory
    {
        return OvertimeRecordFactory::new();
    }
}
