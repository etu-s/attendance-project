<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\MatchedBy;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which employee a raw punch belongs to, fixed when it was matched. A badge
 * later given to someone else does not move old punches.
 *
 * @property int $raw_punch_id
 * @property int $employee_id
 * @property CarbonImmutable $punched_at
 * @property MatchedBy $matched_by
 * @property-read RawPunch $rawPunch
 * @property-read Employee $employee
 */
class PunchMatch extends Model
{
    public const UPDATED_AT = null;

    /** UTC instants, stored as text so ordering and indexes work (CLAUDE.md). */
    protected $dateFormat = 'Y-m-d H:i:s';

    public $incrementing = false;

    protected $primaryKey = 'raw_punch_id';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_punch_id' => 'integer',
            'employee_id' => 'integer',
            'punched_at' => 'immutable_datetime',
            'matched_by' => MatchedBy::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<RawPunch, $this>
     */
    public function rawPunch(): BelongsTo
    {
        return $this->belongsTo(RawPunch::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
