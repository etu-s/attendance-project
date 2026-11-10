<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Contracts\PeriodLock;
use App\Modules\Core\Support\Search;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\EmployeeVisibility;
use App\Modules\Scheduling\Events\ScheduleChanged;
use App\Modules\Scheduling\Models\TemporarySchedule;
use App\Modules\Timetable\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * One-off overrides for one employee. Ranges of one employee never overlap;
 * edit or delete the existing override instead of stacking a second one.
 */
final class TemporaryScheduleService
{
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly EmployeeVisibility $visibility,
        private readonly PeriodLock $locks,
    ) {}

    /**
     * Every override the viewer may see (their visible employees only).
     *
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, TemporarySchedule>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage, User $viewer): LengthAwarePaginator
    {
        $query = TemporarySchedule::query()->with(['shift', 'employee']);

        if (isset($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        if (isset($filters['active_on'])) {
            $day = (string) $filters['active_on'];
            $query->where('date_from', '<=', $day)->where('date_to', '>=', $day);
        }

        if (($filters['kind'] ?? null) === 'day_off') {
            $query->whereNull('shift_id');
        } elseif (($filters['kind'] ?? null) === 'shift') {
            $query->whereNotNull('shift_id');
        }

        if ($search !== null || ! $this->visibility->seesEveryone($viewer)) {
            $employees = Employee::withTrashed()->select('employees.id');
            $this->visibility->apply($employees, $viewer);
            Search::apply($employees, ['employee_code', 'first_name', 'last_name'], $search);
            $query->whereIn('employee_id', $employees);
        }

        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage)->withQueryString();
    }

    /**
     * @return Collection<int, TemporarySchedule>
     */
    public function forEmployee(Employee $employee): Collection
    {
        return TemporarySchedule::query()->with('shift')->where('employee_id', $employee->id)->orderByDesc('date_from')->get();
    }

    /**
     * @param  array{date_from: string, date_to: string, shift_id?: int|null, anchor_date?: string|null, reason?: string|null}  $data
     */
    public function create(Employee $employee, array $data): TemporarySchedule
    {
        $attributes = $this->validated($employee, $data, null);
        $this->locks->assertOpen(LocalDate::parse((string) $data['date_from']), LocalDate::parse((string) $data['date_to']), 'This temporary schedule');
        $temporary = TemporarySchedule::query()->create($attributes + ['employee_id' => $employee->id]);
        $this->changed($temporary, $temporary->date_from, $temporary->date_to);

        return $temporary->load('shift');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TemporarySchedule $temporary, array $data): TemporarySchedule
    {
        $before = [$temporary->date_from, $temporary->date_to];
        $merged = array_merge([
            'date_from' => $temporary->date_from->toDateString(),
            'date_to' => $temporary->date_to->toDateString(),
            'shift_id' => $temporary->shift_id,
            'anchor_date' => $temporary->anchor_date?->toDateString(),
            'reason' => $temporary->reason,
        ], $data);

        $employee = Employee::withTrashed()->findOrFail($temporary->employee_id);
        $attributes = $this->validated($employee, $merged, $temporary->id);
        $newFrom = LocalDate::parse((string) $merged['date_from']);
        $newTo = LocalDate::parse((string) $merged['date_to']);
        // Both the days it covered and the days it will cover change.
        $this->locks->assertOpen($before[0], $before[1], 'This temporary schedule');
        $this->locks->assertOpen($newFrom, $newTo, 'This temporary schedule');
        $temporary->update($attributes);
        $this->changed($temporary, min($before[0], $temporary->date_from), max($before[1], $temporary->date_to));

        return $temporary->load('shift');
    }

    public function delete(TemporarySchedule $temporary): void
    {
        $this->locks->assertOpen($temporary->date_from, $temporary->date_to, 'Removing this temporary schedule');
        $temporary->delete();
        $this->changed($temporary, $temporary->date_from, $temporary->date_to);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(Employee $employee, array $data, ?int $ignoreId): array
    {
        $from = LocalDate::parse((string) $data['date_from']);
        $to = LocalDate::parse((string) $data['date_to']);
        $shiftId = isset($data['shift_id']) ? (int) $data['shift_id'] : null;

        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['date_to' => 'The end date is before the start date.']);
        }

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['date_to' => 'A temporary schedule can span at most '.self::MAX_DAYS.' days.']);
        }

        if ($shiftId !== null && ! Shift::query()->whereKey($shiftId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['shift_id' => 'Choose an active shift.']);
        }

        $overlap = TemporarySchedule::query()
            ->where('employee_id', $employee->id)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where('date_from', '<=', $to->format(LocalDate::FORMAT))
            ->where('date_to', '>=', $from->format(LocalDate::FORMAT))
            ->first();

        if ($overlap !== null) {
            throw ValidationException::withMessages(['date_from' => sprintf(
                'Overlaps the temporary schedule from %s to %s.',
                $overlap->date_from->toDateString(),
                $overlap->date_to->toDateString(),
            )]);
        }

        return [
            'date_from' => $from,
            'date_to' => $to,
            'shift_id' => $shiftId,
            // A day off has no cycle; a shift's cycle starts on the first day unless set.
            'anchor_date' => $shiftId === null ? null : (isset($data['anchor_date']) ? LocalDate::parse((string) $data['anchor_date']) : $from),
            'reason' => $data['reason'] ?? null,
        ];
    }

    private function changed(TemporarySchedule $temporary, CarbonImmutable $from, CarbonImmutable $to): void
    {
        event(new ScheduleChanged('employee', $temporary->employee_id, $from->format(LocalDate::FORMAT), $to->format(LocalDate::FORMAT)));
    }
}
