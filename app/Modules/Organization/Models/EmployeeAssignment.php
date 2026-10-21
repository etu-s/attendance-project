<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Department + location of an employee from effective_from to effective_to
 * (inclusive; null = open-ended). Written only by AssignmentService.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $department_id
 * @property int|null $location_id
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property-read Department $department
 * @property-read Location|null $location
 */
class EmployeeAssignment extends Model
{
    use Auditable;

    protected $fillable = ['employee_id', 'department_id', 'location_id', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'department_id' => 'integer',
            'location_id' => 'integer',
            'effective_from' => LocalDate::class,
            'effective_to' => LocalDate::class,
        ];
    }

    public function covers(CarbonImmutable $date): bool
    {
        return $this->effective_from->lessThanOrEqualTo($date)
            && ($this->effective_to === null || $this->effective_to->greaterThanOrEqualTo($date));
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
