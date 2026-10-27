<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Leave\Enums\LeaveAccrual;
use App\Modules\Leave\Enums\LedgerKind;
use App\Modules\Leave\Models\LeaveLedgerEntry;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Support\LeaveYears;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes what is due up to $today (leave:accrue runs daily; safe to repeat,
 * each entry is once per period by a unique index):
 *  - yearly grants at the start of the leave year; a joiner gets
 *    entitlement × months remaining (incl. the hire month) / 12, rounded down
 *    to half-days, once employed;
 *  - monthly accruals for each month of the leave year so far, from the hire
 *    month, as cumulative-rounded twelfths (the year adds up exactly);
 *  - at the start of a new leave year, for the previous one: the balance up to
 *    the type's carry-forward cap moves to the new year (a negative balance
 *    moves in full) and the rest expires.
 */
final class LeaveAccrualService
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly LeaveYears $years,
    ) {}

    /**
     * @return int ledger entries written
     */
    public function run(CarbonImmutable $today): int
    {
        $year = $this->years->of($today);
        $start = $this->years->start($year);
        $types = LeaveType::query()->where('is_active', true)->where('unlimited', false)->orderBy('id')->get();

        $written = $this->closePreviousYear($year - 1, $types);

        $accruing = $types->filter(fn (LeaveType $t) => $t->accrual !== LeaveAccrual::None && $t->entitlement_halves > 0);
        if ($accruing->isEmpty()) {
            return $written;
        }

        $employees = Employee::query()
            ->where('hire_date', '<=', $today->format(LocalDate::FORMAT))
            ->where(fn (Builder $q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $start->format(LocalDate::FORMAT)))
            ->orderBy('id')
            ->get(['id', 'hire_date', 'termination_date']);

        foreach ($employees as $employee) {
            foreach ($accruing as $type) {
                $written += $type->accrual === LeaveAccrual::Yearly
                    ? $this->grant($employee, $type, $year, $start)
                    : $this->accrueMonths($employee, $type, $year, $start, $today);
            }
        }

        return $written;
    }

    private function grant(Employee $employee, LeaveType $type, int $year, CarbonImmutable $start): int
    {
        $months = 12;
        if ($employee->hire_date->greaterThan($start)) {
            $months = 13 - $this->years->monthIndex($employee->hire_date);
        }

        $halves = intdiv($type->entitlement_halves * $months, 12);

        return $halves > 0 && $this->ledger->writeOnce($employee->id, $type->id, $year, LedgerKind::Grant, $halves, (string) $year) ? 1 : 0;
    }

    private function accrueMonths(Employee $employee, LeaveType $type, int $year, CarbonImmutable $start, CarbonImmutable $today): int
    {
        $written = 0;
        $current = $this->years->monthIndex($today);

        for ($month = 1; $month <= $current; $month++) {
            $monthStart = $start->addMonths($month - 1);
            $monthEnd = $monthStart->endOfMonth();

            $employed = $employee->hire_date->lessThanOrEqualTo($monthEnd)
                && ($employee->termination_date === null || $employee->termination_date->greaterThanOrEqualTo($monthStart));
            if (! $employed) {
                continue;
            }

            $halves = intdiv($type->entitlement_halves * $month, 12) - intdiv($type->entitlement_halves * ($month - 1), 12);
            if ($halves > 0 && $this->ledger->writeOnce($employee->id, $type->id, $year, LedgerKind::Accrual, $halves, sprintf('%d-%02d', $year, $month))) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * @param  Collection<int, LeaveType>  $types
     */
    private function closePreviousYear(int $previous, Collection $types): int
    {
        $written = 0;
        $key = (string) $previous;

        $balances = LeaveLedgerEntry::query()
            ->where('leave_year', $previous)
            ->whereIn('leave_type_id', $types->modelKeys())
            ->groupBy('employee_id', 'leave_type_id')
            ->selectRaw('employee_id, leave_type_id, sum(amount_halves) as total')
            ->get();

        foreach ($balances as $row) {
            /** @var LeaveType $type */
            $type = $types->find($row->leave_type_id);
            $balance = (int) $row->getAttribute('total');
            $carry = $balance > 0 ? min($balance, (int) $type->carry_forward_max_halves) : $balance;
            $expired = $balance - $carry;

            DB::transaction(function () use ($row, $previous, $key, $carry, $expired, &$written): void {
                if ($expired > 0 && $this->ledger->writeOnce($row->employee_id, $row->leave_type_id, $previous, LedgerKind::Expiry, -$expired, $key)) {
                    $written++;
                }
                if ($carry !== 0 && $this->ledger->writeOnce($row->employee_id, $row->leave_type_id, $previous + 1, LedgerKind::CarryForward, $carry, $key)) {
                    $written++;
                }
            });
        }

        return $written;
    }
}
