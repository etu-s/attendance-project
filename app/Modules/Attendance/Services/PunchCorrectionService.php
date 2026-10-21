<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\CorrectionKind;
use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Attendance\Jobs\RecalculateAttendance;
use App\Modules\Attendance\Models\PunchCorrection;
use App\Modules\Attendance\Models\PunchMatch;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Support\Search;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\EmployeeVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Who may do what (approved 2026-10-21):
 *  - request: anyone with corrections.request for an employee they can see
 *    (employees: themselves; managers: their people; HR: everyone);
 *  - approve/reject: corrections.approve for an employee they can see, never
 *    their own request and never about themselves;
 *  - entered by someone who sees everyone and may approve (HR, Tenant Admin):
 *    approved at once, still audited.
 * Approval queues a recalculation of the work date and its neighbours.
 */
final class PunchCorrectionService
{
    public function __construct(
        private readonly EmployeeVisibility $visibility,
        private readonly TenantClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, PunchCorrection>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage, User $viewer): LengthAwarePaginator
    {
        $query = PunchCorrection::query()->with(['employee', 'requester', 'decider', 'rawPunch']);

        foreach (['status', 'employee_id', 'kind'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('work_date', '>=', (string) $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('work_date', '<=', (string) $filters['to']);
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
     * @param  array{work_date: string, kind: string, punched_at?: string|null, raw_punch_id?: int|null, reason: string}  $data
     */
    public function create(Employee $employee, array $data, User $actor): PunchCorrection
    {
        $workDate = LocalDate::parse($data['work_date']);
        $kind = CorrectionKind::from($data['kind']);

        if ($workDate->greaterThan($this->clock->today())) {
            throw ValidationException::withMessages(['work_date' => 'Attendance for a future date cannot be corrected.']);
        }

        $attributes = [
            'employee_id' => $employee->id,
            'work_date' => $workDate,
            'kind' => $kind,
            'reason' => $data['reason'],
            'requested_by' => $actor->id,
        ];

        if ($kind === CorrectionKind::Add) {
            $at = CarbonImmutable::parse((string) $data['punched_at'])->utc()->startOfSecond();
            // Generous enough for any timezone and an overnight shift ending the next morning.
            if ($at->lessThan($workDate->subDay()) || $at->greaterThanOrEqualTo($workDate->addDays(2))) {
                throw ValidationException::withMessages(['punched_at' => 'The punch time must be on or around the work date.']);
            }
            $attributes['punched_at'] = $at;
        } else {
            $rawId = (int) $data['raw_punch_id'];
            if (! PunchMatch::query()->whereKey($rawId)->where('employee_id', $employee->id)->exists()) {
                throw ValidationException::withMessages(['raw_punch_id' => 'Choose one of this employee\'s punches.']);
            }
            $taken = PunchCorrection::query()->where('raw_punch_id', $rawId)
                ->whereIn('status', [CorrectionStatus::Pending->value, CorrectionStatus::Approved->value])
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages(['raw_punch_id' => 'This punch is already ignored or waiting for approval.']);
            }
            $attributes['raw_punch_id'] = $rawId;
        }

        $autoApprove = $actor->can('corrections.approve') && $this->visibility->seesEveryone($actor) && $employee->user_id !== $actor->id;

        $correction = PunchCorrection::query()->create($attributes + ($autoApprove
            ? ['status' => CorrectionStatus::Approved, 'decided_by' => $actor->id, 'decided_at' => CarbonImmutable::now('UTC')]
            : ['status' => CorrectionStatus::Pending]));

        if ($autoApprove) {
            $this->recalculate($correction);
        }

        return $correction->load(['employee', 'requester', 'decider', 'rawPunch']);
    }

    public function approve(PunchCorrection $correction, User $actor, ?string $note): PunchCorrection
    {
        $this->assertDecidable($correction, $actor);

        $correction->update([
            'status' => CorrectionStatus::Approved,
            'decided_by' => $actor->id,
            'decided_at' => CarbonImmutable::now('UTC'),
            'decision_note' => $note,
        ]);
        $this->recalculate($correction);

        return $correction->load(['employee', 'requester', 'decider', 'rawPunch']);
    }

    public function reject(PunchCorrection $correction, User $actor, ?string $note): PunchCorrection
    {
        $this->assertDecidable($correction, $actor);

        $correction->update([
            'status' => CorrectionStatus::Rejected,
            'decided_by' => $actor->id,
            'decided_at' => CarbonImmutable::now('UTC'),
            'decision_note' => $note,
        ]);

        return $correction->load(['employee', 'requester', 'decider', 'rawPunch']);
    }

    private function assertDecidable(PunchCorrection $correction, User $actor): void
    {
        if ($correction->status !== CorrectionStatus::Pending) {
            throw new BusinessRuleException('This correction was already decided.', 'correction_decided', 409);
        }

        if ($correction->requested_by === $actor->id || $correction->employee->user_id === $actor->id) {
            throw new BusinessRuleException('You cannot decide your own correction.', 'correction_own', 403);
        }
    }

    private function recalculate(PunchCorrection $correction): void
    {
        RecalculateAttendance::dispatch(
            [$correction->employee_id],
            $correction->work_date->subDay()->format(LocalDate::FORMAT),
            $correction->work_date->addDay()->format(LocalDate::FORMAT),
        );
    }
}
