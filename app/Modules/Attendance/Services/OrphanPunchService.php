<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\OrphanStatus;
use App\Modules\Attendance\Jobs\RecalculateAttendance;
use App\Modules\Attendance\Models\EmployeePunchIdentifier;
use App\Modules\Attendance\Models\OrphanPunch;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Support\Search;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Punches whose identifier matched nobody. Linking teaches the system the
 * identifier (it becomes one of the employee's punch identifiers) and gives
 * the employee every open orphan punch that carried it; dismissing marks a
 * punch as not an employee's (test badge, visitor).
 */
final class OrphanPunchService
{
    public function __construct(
        private readonly PunchProcessor $processor,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, OrphanPunch>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage): LengthAwarePaginator
    {
        $query = OrphanPunch::query()
            ->with(['rawPunch.source', 'employee'])
            ->where('status', $filters['status'] ?? OrphanStatus::Open->value);

        Search::apply($query, ['employee_identifier'], $search);

        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage)->withQueryString();
    }

    /**
     * @return int punches linked
     */
    public function link(OrphanPunch $orphan, Employee $employee, User $actor): int
    {
        $this->assertOpen($orphan);
        $identifier = $orphan->employee_identifier;

        /** @var Collection<int, OrphanPunch> $linked */
        $linked = DB::transaction(function () use ($identifier, $employee, $actor): Collection {
            $known = mb_strtolower($employee->employee_code) === mb_strtolower($identifier)
                || EmployeePunchIdentifier::query()->where('employee_id', $employee->id)->where('identifier', $identifier)->exists();

            if (! $known) {
                PunchIdentifierService::assertAvailable($identifier, $employee);
                EmployeePunchIdentifier::query()->create([
                    'employee_id' => $employee->id,
                    'identifier' => $identifier,
                    'label' => 'Linked from an unmatched punch',
                ]);
            }

            $open = OrphanPunch::query()->open()->with('rawPunch')->where('employee_identifier', $identifier)->get();
            $this->processor->matchManually($open->pluck('raw_punch_id')->all(), $employee->id);

            OrphanPunch::query()->whereKey($open->modelKeys())->update([
                'status' => OrphanStatus::Resolved->value,
                'employee_id' => $employee->id,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now('UTC'),
                'updated_at' => CarbonImmutable::now('UTC'),
            ]);

            return $open;
        });

        $times = $linked->map(fn (OrphanPunch $o) => $o->rawPunch)->reject(fn ($raw) => $raw->too_old)->map(fn ($raw) => $raw->punched_at);
        if ($times->isNotEmpty()) {
            RecalculateAttendance::dispatch(
                [$employee->id],
                LocalDate::parse($times->min()->format(LocalDate::FORMAT))->subDay()->format(LocalDate::FORMAT),
                LocalDate::parse($times->max()->format(LocalDate::FORMAT))->addDay()->format(LocalDate::FORMAT),
            );
        }

        return $linked->count();
    }

    public function dismiss(OrphanPunch $orphan, User $actor): OrphanPunch
    {
        $this->assertOpen($orphan);

        $orphan->update([
            'status' => OrphanStatus::Dismissed,
            'decided_by' => $actor->id,
            'decided_at' => CarbonImmutable::now('UTC'),
        ]);

        return $orphan;
    }

    private function assertOpen(OrphanPunch $orphan): void
    {
        if ($orphan->status !== OrphanStatus::Open) {
            throw new BusinessRuleException('This punch was already linked or dismissed.', 'orphan_closed', 409);
        }
    }
}
