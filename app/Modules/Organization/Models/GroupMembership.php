<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's membership of a group from effective_from to effective_to
 * (inclusive; null = open-ended). Written only by MembershipService.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $group_id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property-read Group $group
 */
class GroupMembership extends Model
{
    use Auditable;

    protected $fillable = ['employee_id', 'group_id', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'group_id' => 'integer',
            'effective_from' => LocalDate::class,
            'effective_to' => LocalDate::class,
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
