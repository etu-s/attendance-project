<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Models;

use App\Modules\Timetable\Enums\BreakKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of a timetable's time rules; replaced as a set by TimetableService
 * (audited on the timetable, not per row).
 *
 * @property int $id
 * @property int $timetable_id
 * @property BreakKind $kind
 * @property int|null $start_offset
 * @property int $duration_minutes
 * @property bool $is_paid
 * @property int $position
 */
class TimetableBreak extends Model
{
    public $timestamps = false;

    protected $fillable = ['timetable_id', 'kind', 'start_offset', 'duration_minutes', 'is_paid', 'position'];

    protected function casts(): array
    {
        return [
            'kind' => BreakKind::class,
            'start_offset' => 'integer',
            'duration_minutes' => 'integer',
            'is_paid' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return array{kind: string, start_offset: int|null, duration_minutes: int, is_paid: bool}
     */
    public function snapshot(): array
    {
        return [
            'kind' => $this->kind->value,
            'start_offset' => $this->start_offset,
            'duration_minutes' => $this->duration_minutes,
            'is_paid' => $this->is_paid,
        ];
    }

    /**
     * @return BelongsTo<Timetable, $this>
     */
    public function timetable(): BelongsTo
    {
        return $this->belongsTo(Timetable::class);
    }
}
