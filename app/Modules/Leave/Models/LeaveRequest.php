<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Concerns\Auditable;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Database\Factories\LeaveRequestFactory;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Leave for a date range. half_start: the first day counts its second half
 * only; half_end: the last day counts its first half only. days_halves is
 * the counted (scheduled working) time in half-days.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property bool $half_start
 * @property bool $half_end
 * @property int $days_halves
 * @property string|null $reason
 * @property LeaveRequestStatus $status
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $created_at
 * @property-read Employee $employee
 * @property-read LeaveType $type
 * @property-read Collection<int, LeaveRequestDay> $days
 * @property-read Collection<int, LeaveRequestAttachment> $attachments
 * @property-read User|null $requester
 * @property-read User|null $decider
 */
class LeaveRequest extends Model
{
    use Auditable, SoftDeletes;

    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    /** UTC instants, stored as text so ordering and indexes work (CLAUDE.md). */
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'employee_id', 'leave_type_id', 'start_date', 'end_date', 'half_start', 'half_end', 'days_halves', 'reason',
        'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note', 'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'leave_type_id' => 'integer',
            'start_date' => LocalDate::class,
            'end_date' => LocalDate::class,
            'half_start' => 'boolean',
            'half_end' => 'boolean',
            'days_halves' => 'integer',
            'status' => LeaveRequestStatus::class,
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'immutable_datetime',
            'cancelled_by' => 'integer',
            'cancelled_at' => 'immutable_datetime',
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
     * @return BelongsTo<LeaveType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /**
     * @return HasMany<LeaveRequestDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(LeaveRequestDay::class)->orderBy('date');
    }

    /**
     * @return HasMany<LeaveRequestAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(LeaveRequestAttachment::class)->orderBy('id');
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

    protected static function newFactory(): LeaveRequestFactory
    {
        return LeaveRequestFactory::new();
    }
}
