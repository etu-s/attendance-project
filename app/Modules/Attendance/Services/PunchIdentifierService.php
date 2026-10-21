<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\EmployeePunchIdentifier;
use App\Modules\Attendance\Models\OrphanPunch;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Extra identifiers per employee. An identifier is unique across the
 * workspace and may not equal any employee code, so a punch always has at
 * most one owner. Adding one links the open orphan punches that carried it.
 */
final class PunchIdentifierService
{
    public function __construct(
        private readonly OrphanPunchService $orphans,
    ) {}

    /**
     * @return Collection<int, EmployeePunchIdentifier>
     */
    public function forEmployee(Employee $employee): Collection
    {
        return EmployeePunchIdentifier::query()->where('employee_id', $employee->id)->orderBy('identifier')->get();
    }

    public function create(Employee $employee, string $identifier, ?string $label, User $actor): EmployeePunchIdentifier
    {
        $identifier = trim($identifier);
        self::assertAvailable($identifier, $employee);

        $created = DB::transaction(fn () => EmployeePunchIdentifier::query()->create([
            'employee_id' => $employee->id,
            'identifier' => $identifier,
            'label' => $label,
        ]));

        $open = OrphanPunch::query()->open()->where('employee_identifier', $identifier)->first();
        if ($open !== null) {
            $this->orphans->link($open, $employee, $actor);
        }

        return $created;
    }

    public function delete(EmployeePunchIdentifier $identifier): void
    {
        // Punches already matched stay with the employee (PunchMatch).
        $identifier->delete();
    }

    /**
     * Throws unless $identifier is free for $employee (its own code counts as taken: it already works).
     */
    public static function assertAvailable(string $identifier, Employee $employee): void
    {
        $owner = Employee::withTrashed()->where('employee_code', $identifier)->first();

        if ($owner !== null) {
            throw ValidationException::withMessages(['identifier' => $owner->id === $employee->id
                ? 'This is the employee code, which already identifies the employee.'
                : 'This is another employee\'s code.']);
        }

        if (EmployeePunchIdentifier::query()->where('identifier', $identifier)->exists()) {
            throw ValidationException::withMessages(['identifier' => 'This identifier already belongs to an employee.']);
        }
    }
}
