<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\DayPortion;
use App\Modules\Core\Events\LeaveChanged;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Enums\LedgerKind;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestDay;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The life of a leave request. Only scheduled working days count; balances
 * move only through LeaveLedger entries:
 *  - approval debits the counted half-days per leave year (`request`);
 *  - cancelling approved leave credits back what it took (`cancellation`);
 *  - a schedule or holiday change recounts it and writes the difference
 *    (`recount`).
 * Every change to approved leave fires LeaveChanged (attendance recalculates).
 */
final class LeaveRequestService
{
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly LeaveDayCounter $counter,
        private readonly LeaveLedger $ledger,
        private readonly LeaveRules $rules,
    ) {}

    /**
     * What a request would count and do to the balance, without saving.
     *
     * @return array{days: list<array{date: string, portion: DayPortion, leave_year: int}>, halves: int, years: list<array{leave_year: int, halves: int, available: int|null, after: int|null}>, problem: string|null}
     */
    public function preview(Employee $employee, LeaveType $type, CarbonImmutable $from, CarbonImmutable $to, bool $halfStart, bool $halfEnd, ?LeaveRequest $except = null): array
    {
        $this->assertRange($from, $to, $halfStart, $halfEnd);
        $days = $this->counter->count($employee, $from, $to, $halfStart, $halfEnd);

        $years = [];
        $problem = $days === [] ? 'There are no scheduled working days in this range.' : null;

        foreach (LeaveDayCounter::byYear($days) as $year => $halves) {
            $available = $type->unlimited
                ? null
                : $this->ledger->balance($employee->id, $type->id, $year) - $this->ledger->pending($employee->id, $type->id, $year, $except?->id);
            $after = $available === null ? null : $available - $halves;

            if ($after !== null && $after < -$type->negative_limit_halves) {
                $problem ??= sprintf('Not enough %s balance for the %d leave year.', $type->name, $year);
            }

            $years[] = ['leave_year' => $year, 'halves' => $halves, 'available' => $available, 'after' => $after];
        }

        return [
            'days' => $days,
            'halves' => array_sum(array_map(fn (array $d) => $d['portion']->halves(), $days)),
            'years' => $years,
            'problem' => $problem,
        ];
    }

    /**
     * @param  array{leave_type_id: int, start_date: string, end_date: string, half_start?: bool, half_end?: bool, reason?: string|null}  $data
     */
    public function create(Employee $employee, array $data, User $actor): LeaveRequest
    {
        $type = $this->activeType((int) $data['leave_type_id']);
        $from = LocalDate::parse($data['start_date']);
        $to = LocalDate::parse($data['end_date']);
        $halfStart = (bool) ($data['half_start'] ?? false);
        $halfEnd = (bool) ($data['half_end'] ?? false);

        $preview = $this->preview($employee, $type, $from, $to, $halfStart, $halfEnd);
        if ($preview['problem'] !== null) {
            throw ValidationException::withMessages(['start_date' => $preview['problem']]);
        }
        $this->assertNoOverlap($employee, $preview['days'], null);

        $autoApprove = $this->rules->autoApproves($actor, $employee);

        $request = DB::transaction(function () use ($employee, $type, $from, $to, $halfStart, $halfEnd, $data, $actor, $preview, $autoApprove): LeaveRequest {
            $request = LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $from,
                'end_date' => $to,
                'half_start' => $halfStart,
                'half_end' => $halfEnd,
                'days_halves' => $preview['halves'],
                'reason' => $data['reason'] ?? null,
                'status' => $autoApprove ? LeaveRequestStatus::Approved : LeaveRequestStatus::Pending,
                'requested_by' => $actor->id,
                'decided_by' => $autoApprove ? $actor->id : null,
                'decided_at' => $autoApprove ? CarbonImmutable::now('UTC') : null,
            ]);
            $this->storeDays($request, $preview['days']);

            if ($autoApprove) {
                $this->debit($request, $preview['days'], $actor);
            }

            return $request;
        });

        if ($autoApprove) {
            $this->changed($request);
        }

        return $request->load(['employee', 'type', 'requester', 'decider']);
    }

    public function approve(LeaveRequest $request, User $actor, ?string $note): LeaveRequest
    {
        $request->loadMissing(['employee', 'type']);
        $this->assertDecidable($request, $actor);

        // Balances may have moved since the request was made.
        $preview = $this->preview($request->employee, $request->type, $request->start_date, $request->end_date, $request->half_start, $request->half_end, $request);
        if ($preview['problem'] !== null) {
            throw new BusinessRuleException($preview['problem'], 'leave_balance');
        }

        DB::transaction(function () use ($request, $actor, $note, $preview): void {
            $request->update([
                'status' => LeaveRequestStatus::Approved,
                'decided_by' => $actor->id,
                'decided_at' => CarbonImmutable::now('UTC'),
                'decision_note' => $note,
                'days_halves' => $preview['halves'],
            ]);
            $this->storeDays($request, $preview['days']);
            $this->debit($request, $preview['days'], $actor);
        });

        $this->changed($request);

        return $request->load(['employee', 'type', 'requester', 'decider']);
    }

    public function reject(LeaveRequest $request, User $actor, ?string $note): LeaveRequest
    {
        $request->loadMissing(['employee', 'type']);
        $this->assertDecidable($request, $actor);

        $request->update([
            'status' => LeaveRequestStatus::Rejected,
            'decided_by' => $actor->id,
            'decided_at' => CarbonImmutable::now('UTC'),
            'decision_note' => $note,
        ]);

        return $request->load(['employee', 'type', 'requester', 'decider']);
    }

    public function cancel(LeaveRequest $request, User $actor): LeaveRequest
    {
        $request->loadMissing(['employee', 'type']);

        if (! $this->rules->canCancel($actor, $request)) {
            throw new BusinessRuleException(
                $request->status->isActive() ? 'You cannot cancel this leave; ask HR.' : 'This request is already closed.',
                'leave_not_cancellable',
                $request->status->isActive() ? 403 : 409,
            );
        }

        $wasApproved = $request->status === LeaveRequestStatus::Approved;

        DB::transaction(function () use ($request, $actor, $wasApproved): void {
            if ($wasApproved) {
                foreach ($this->ledger->taken($request->id) as $year => $halves) {
                    $this->ledger->write($request->employee_id, $request->leave_type_id, $year, LedgerKind::Cancellation, $halves, $request->id, null, $actor->id);
                }
            }

            $request->update([
                'status' => LeaveRequestStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => CarbonImmutable::now('UTC'),
            ]);
        });

        if ($wasApproved) {
            $this->changed($request);
        }

        return $request->load(['employee', 'type', 'requester', 'decider']);
    }

    /**
     * Recounts active requests overlapping a range after a schedule or holiday
     * change (null employees = everyone, null $to = open-ended). Approved
     * requests get a `recount` ledger entry for the difference.
     *
     * @param  list<int>|null  $employeeIds
     * @return int requests whose count changed
     */
    public function recountOverlapping(?array $employeeIds, CarbonImmutable $from, ?CarbonImmutable $to): int
    {
        $requests = LeaveRequest::query()
            ->with(['employee', 'type', 'days'])
            ->whereIn('status', [LeaveRequestStatus::Pending->value, LeaveRequestStatus::Approved->value])
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->where('end_date', '>=', $from->format(LocalDate::FORMAT))
            ->when($to !== null, fn ($q) => $q->where('start_date', '<=', $to->format(LocalDate::FORMAT)))
            ->orderBy('id')
            ->get();

        $changed = 0;
        foreach ($requests as $request) {
            $days = $this->counter->count($request->employee, $request->start_date, $request->end_date, $request->half_start, $request->half_end);
            $old = $request->days->map(fn (LeaveRequestDay $d) => $d->date->format(LocalDate::FORMAT).'|'.$d->portion->value)->all();
            $new = array_map(fn (array $d) => $d['date'].'|'.$d['portion']->value, $days);

            if ($old === $new) {
                continue;
            }

            DB::transaction(function () use ($request, $days): void {
                $before = $this->ledger->taken($request->id);
                $this->storeDays($request, $days);
                $request->update(['days_halves' => array_sum(array_map(fn (array $d) => $d['portion']->halves(), $days))]);

                if ($request->status === LeaveRequestStatus::Approved) {
                    $after = LeaveDayCounter::byYear($days);
                    foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $year) {
                        $difference = ($after[$year] ?? 0) - ($before[$year] ?? 0);
                        if ($difference !== 0) {
                            $this->ledger->write($request->employee_id, $request->leave_type_id, $year, LedgerKind::Recount, -$difference, $request->id, 'Schedule or holiday changed');
                        }
                    }
                }
            });

            if ($request->status === LeaveRequestStatus::Approved) {
                $this->changed($request);
            }
            $changed++;
        }

        return $changed;
    }

    /**
     * @param  list<array{date: string, portion: DayPortion, leave_year: int}>  $days
     */
    private function debit(LeaveRequest $request, array $days, User $actor): void
    {
        foreach (LeaveDayCounter::byYear($days) as $year => $halves) {
            $this->ledger->write($request->employee_id, $request->leave_type_id, $year, LedgerKind::Request, -$halves, $request->id, null, $actor->id);
        }
    }

    /**
     * @param  list<array{date: string, portion: DayPortion, leave_year: int}>  $days
     */
    private function storeDays(LeaveRequest $request, array $days): void
    {
        LeaveRequestDay::query()->where('leave_request_id', $request->id)->delete();

        if ($days !== []) {
            DB::table('leave_request_days')->insert(array_map(fn (array $d) => [
                'leave_request_id' => $request->id,
                'employee_id' => $request->employee_id,
                'date' => $d['date'],
                'portion' => $d['portion']->value,
                'leave_year' => $d['leave_year'],
            ], $days));
        }

        $request->unsetRelation('days');
    }

    /**
     * Two active requests may share a date only as opposite halves.
     *
     * @param  list<array{date: string, portion: DayPortion, leave_year: int}>  $days
     */
    private function assertNoOverlap(Employee $employee, array $days, ?int $exceptRequestId): void
    {
        if ($days === []) {
            return;
        }

        $taken = DB::table('leave_request_days')
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_days.leave_request_id')
            ->where('leave_request_days.employee_id', $employee->id)
            ->whereIn('leave_request_days.date', array_column($days, 'date'))
            ->whereIn('leave_requests.status', [LeaveRequestStatus::Pending->value, LeaveRequestStatus::Approved->value])
            ->whereNull('leave_requests.deleted_at')
            ->when($exceptRequestId !== null, fn ($q) => $q->where('leave_requests.id', '!=', $exceptRequestId))
            ->get(['leave_request_days.date', 'leave_request_days.portion'])
            ->groupBy('date');

        foreach ($days as $day) {
            foreach ($taken->get($day['date'], collect()) as $existing) {
                $clash = $day['portion'] === DayPortion::Full
                    || $existing->portion === DayPortion::Full->value
                    || $existing->portion === $day['portion']->value;

                if ($clash) {
                    throw ValidationException::withMessages(['start_date' => "This overlaps leave already requested on {$day['date']}."]);
                }
            }
        }
    }

    private function assertRange(CarbonImmutable $from, CarbonImmutable $to, bool $halfStart, bool $halfEnd): void
    {
        if ($to->lessThan($from)) {
            throw ValidationException::withMessages(['end_date' => 'The leave ends before it starts.']);
        }
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['end_date' => 'Request at most '.self::MAX_DAYS.' days at a time.']);
        }
        if ($halfStart && $halfEnd && $from->equalTo($to)) {
            throw ValidationException::withMessages(['half_end' => 'A single half day is either the first or the second half.']);
        }
    }

    private function assertDecidable(LeaveRequest $request, User $actor): void
    {
        if ($request->status !== LeaveRequestStatus::Pending) {
            throw new BusinessRuleException('This request was already decided.', 'leave_decided', 409);
        }

        if (! $this->rules->canDecide($actor, $request)) {
            throw $this->rules->isOwn($actor, $request)
                ? new BusinessRuleException('You cannot decide your own leave.', 'leave_own', 403)
                : new BusinessRuleException('You cannot decide this request.', 'leave_forbidden', 403);
        }
    }

    private function activeType(int $id): LeaveType
    {
        $type = LeaveType::query()->find($id);

        if ($type === null || ! $type->is_active) {
            throw ValidationException::withMessages(['leave_type_id' => 'Choose an active leave type.']);
        }

        return $type;
    }

    private function changed(LeaveRequest $request): void
    {
        event(new LeaveChanged(
            $request->employee_id,
            $request->start_date->format(LocalDate::FORMAT),
            $request->end_date->format(LocalDate::FORMAT),
        ));
    }
}
