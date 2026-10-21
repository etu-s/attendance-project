<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Timetable\Database\Factories\TimetableFactory;
use App\Modules\Timetable\Enums\TimetableType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property TimetableType $type
 * @property string|null $color
 * @property int $start_minute
 * @property int $duration_minutes
 * @property int|null $required_minutes
 * @property int|null $core_start_offset
 * @property int|null $core_duration_minutes
 * @property int|null $late_grace_minutes
 * @property int|null $early_leave_grace_minutes
 * @property int|null $check_in_before_minutes
 * @property int|null $check_out_after_minutes
 * @property int|null $min_work_minutes
 * @property bool $is_active
 * @property-read Collection<int, TimetableBreak> $breaks
 */
class Timetable extends Model
{
    use Auditable;

    /** @use HasFactory<TimetableFactory> */
    use HasFactory;

    /**
     * Attributes that define when work happens. Once a schedule uses the
     * timetable these are read-only (TimetableService), so past days keep the
     * rules they were evaluated with.
     */
    public const TIME_RULES = [
        'type', 'start_minute', 'duration_minutes', 'required_minutes', 'core_start_offset',
        'core_duration_minutes', 'late_grace_minutes', 'early_leave_grace_minutes',
        'check_in_before_minutes', 'check_out_after_minutes', 'min_work_minutes',
    ];

    protected $fillable = [
        'name', 'code', 'type', 'color', 'start_minute', 'duration_minutes', 'required_minutes',
        'core_start_offset', 'core_duration_minutes', 'late_grace_minutes', 'early_leave_grace_minutes',
        'check_in_before_minutes', 'check_out_after_minutes', 'min_work_minutes', 'is_active',
    ];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'type' => TimetableType::class,
            'start_minute' => 'integer',
            'duration_minutes' => 'integer',
            'required_minutes' => 'integer',
            'core_start_offset' => 'integer',
            'core_duration_minutes' => 'integer',
            'late_grace_minutes' => 'integer',
            'early_leave_grace_minutes' => 'integer',
            'check_in_before_minutes' => 'integer',
            'check_out_after_minutes' => 'integer',
            'min_work_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * End as minutes from the start of the start day (may exceed 1440 = next day).
     */
    public function endMinute(): int
    {
        return $this->start_minute + $this->duration_minutes;
    }

    public function crossesMidnight(): bool
    {
        return $this->endMinute() > 1440;
    }

    /**
     * Paid working minutes of a fixed timetable: its length minus unpaid breaks.
     * For a flexible timetable this is the required minutes.
     */
    public function workMinutes(): int
    {
        if ($this->type === TimetableType::Flexible) {
            return (int) $this->required_minutes;
        }

        return $this->duration_minutes - (int) $this->breaks->where('is_paid', false)->sum('duration_minutes');
    }

    /**
     * @return HasMany<TimetableBreak, $this>
     */
    public function breaks(): HasMany
    {
        return $this->hasMany(TimetableBreak::class)->orderBy('position');
    }

    /**
     * @return HasMany<ShiftDay, $this>
     */
    public function shiftDays(): HasMany
    {
        return $this->hasMany(ShiftDay::class);
    }

    protected static function newFactory(): TimetableFactory
    {
        return TimetableFactory::new();
    }
}
