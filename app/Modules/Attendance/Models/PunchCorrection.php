<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Database\Factories\PunchCorrectionFactory;
use App\Modules\Attendance\Enums\CorrectionKind;
use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Concerns\Auditable;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A requested change to the punches of one work date. Approved corrections
 * feed the calculator; raw punches are never touched.
 *
 * @property int $id
 * @property int $employee_id
 * @property CarbonImmutable $work_date
 * @property CorrectionKind $kind
 * @property CarbonImmutable|null $punched_at
 * @property int|null $raw_punch_id
 * @property string $reason
 * @property CorrectionStatus $status
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable|null $created_at
 * @property-read Employee $employee
 * @property-read RawPunch|null $rawPunch
 * @property-read User|null $requester
 * @property-read User|null $decider
 */
class PunchCorrection extends Model
{
    use Auditable;

    /** @use HasFactory<PunchCorrectionFactory> */
    use HasFactory;

    /** UTC instants, stored as text so ordering and indexes work (CLAUDE.md). */
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'employee_id', 'work_date', 'kind', 'punched_at', 'raw_punch_id', 'reason', 'status',
        'requested_by', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'work_date' => LocalDate::class,
            'kind' => CorrectionKind::class,
            'punched_at' => 'immutable_datetime',
            'raw_punch_id' => 'integer',
            'status' => CorrectionStatus::class,
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * @return BelongsTo<RawPunch, $this>
     */
    public function rawPunch(): BelongsTo
    {
        return $this->belongsTo(RawPunch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    protected static function newFactory(): PunchCorrectionFactory
    {
        return PunchCorrectionFactory::new();
    }
}
