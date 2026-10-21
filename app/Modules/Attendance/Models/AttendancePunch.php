<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\PunchRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one punch (raw, or an approved "add" correction) was used on a day.
 * Rebuilt on every recalculation.
 *
 * @property int $id
 * @property int $attendance_day_id
 * @property int|null $attendance_period_id
 * @property int|null $raw_punch_id
 * @property int|null $punch_correction_id
 * @property CarbonImmutable $punched_at
 * @property PunchRole $role
 * @property-read RawPunch|null $rawPunch
 */
class AttendancePunch extends Model
{
    /** UTC instants, stored as text so ordering and indexes work (CLAUDE.md). */
    protected $dateFormat = 'Y-m-d H:i:s';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attendance_day_id' => 'integer',
            'attendance_period_id' => 'integer',
            'raw_punch_id' => 'integer',
            'punch_correction_id' => 'integer',
            'punched_at' => 'immutable_datetime',
            'role' => PunchRole::class,
        ];
    }

    /**
     * @return BelongsTo<RawPunch, $this>
     */
    public function rawPunch(): BelongsTo
    {
        return $this->belongsTo(RawPunch::class);
    }
}
