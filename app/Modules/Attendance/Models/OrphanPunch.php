<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\OrphanStatus;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A raw punch whose identifier matched no employee on arrival.
 *
 * @property int $id
 * @property int $raw_punch_id
 * @property string $employee_identifier
 * @property OrphanStatus $status
 * @property int|null $employee_id
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property-read RawPunch $rawPunch
 * @property-read Employee|null $employee
 */
class OrphanPunch extends Model
{
    /** UTC instants, stored as text so ordering and indexes work (CLAUDE.md). */
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_punch_id' => 'integer',
            'status' => OrphanStatus::class,
            'employee_id' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<OrphanPunch>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('status', OrphanStatus::Open->value);
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
