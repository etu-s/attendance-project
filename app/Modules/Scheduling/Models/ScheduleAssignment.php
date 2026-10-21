<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Concerns\Auditable;
use App\Modules\Scheduling\Database\Factories\ScheduleAssignmentFactory;
use App\Modules\Scheduling\Enums\ScheduleTarget;
use App\Modules\Timetable\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shift applied to a target from effective_from to effective_to (inclusive;
 * null = open-ended). Written only by ScheduleAssignmentService.
 *
 * @property int $id
 * @property ScheduleTarget $target_type
 * @property int|null $target_id
 * @property int $shift_id
 * @property CarbonImmutable $anchor_date
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property-read Shift $shift
 */
class ScheduleAssignment extends Model
{
    use Auditable;

    /** @use HasFactory<ScheduleAssignmentFactory> */
    use HasFactory;

    protected $fillable = ['target_type', 'target_id', 'shift_id', 'anchor_date', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'target_type' => ScheduleTarget::class,
            'target_id' => 'integer',
            'shift_id' => 'integer',
            'anchor_date' => LocalDate::class,
            'effective_from' => LocalDate::class,
            'effective_to' => LocalDate::class,
        ];
    }

    public function covers(CarbonImmutable $date): bool
    {
        return $this->effective_from->lessThanOrEqualTo($date)
            && ($this->effective_to === null || $this->effective_to->greaterThanOrEqualTo($date));
    }

    public function targetKey(): string
    {
        return $this->target_type->value.':'.($this->target_id ?? '');
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    protected static function newFactory(): ScheduleAssignmentFactory
    {
        return ScheduleAssignmentFactory::new();
    }
}
