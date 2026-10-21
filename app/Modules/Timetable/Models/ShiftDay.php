<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One timetable on one day of a shift's cycle. Replaced as a set by
 * ShiftService::setDays() (audited on the shift).
 *
 * @property int $id
 * @property int $shift_id
 * @property int $day_index
 * @property int $timetable_id
 * @property-read Timetable $timetable
 */
class ShiftDay extends Model
{
    public $timestamps = false;

    protected $fillable = ['shift_id', 'day_index', 'timetable_id'];

    protected function casts(): array
    {
        return ['shift_id' => 'integer', 'day_index' => 'integer', 'timetable_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Timetable, $this>
     */
    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::class);
    }
}
