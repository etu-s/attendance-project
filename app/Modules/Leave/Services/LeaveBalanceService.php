<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Enums\LedgerKind;
use App\Modules\Leave\Models\LeaveLedgerEntry;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Organization\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Balances are read from the ledger, never stored. Per type and leave year:
 *   entitled  = grants + accruals + carry-forward
 *   used      = requests + recounts + cancellations (as a positive number)
 *   adjusted  = HR adjustments; expired = year-end expiry
 *   balance   = sum of everything; available = balance − pending requests
 */
final class LeaveBalanceService
{
    public function __construct(
        private readonly LeaveLedger $ledger,
    ) {}

    /**
     * Active types, plus inactive ones the employee has entries for.
     *
     * @return list<array{type: LeaveType, entitled: int, used: int, adjusted: int, expired: int, balance: int, pending: int, available: int|null}>
     */
    public function forEmployee(Employee $employee, int $leaveYear): array
    {
        $sums = LeaveLedgerEntry::query()
            ->where('employee_id', $employee->id)
            ->where('leave_year', $leaveYear)
            ->selectRaw('leave_type_id, kind, sum(amount_halves) as total')
            ->groupBy('leave_type_id', 'kind')
            ->get()
            ->groupBy('leave_type_id');

        $pending = DB::table('leave_request_days')
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_days.leave_request_id')
            ->where('leave_request_days.employee_id', $employee->id)
            ->where('leave_request_days.leave_year', $leaveYear)
            ->where('leave_requests.status', LeaveRequestStatus::Pending->value)
            ->whereNull('leave_requests.deleted_at')
            ->groupBy('leave_requests.leave_type_id')
            ->selectRaw("leave_requests.leave_type_id, sum(case leave_request_days.portion when 'full' then 2 else 1 end) as total")
            ->pluck('total', 'leave_type_id');

        $types = LeaveType::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $sums->keys()))
            ->orderBy('name')
            ->get();

        $balances = [];
        foreach ($types as $type) {
            $kinds = [];
            foreach ($sums->get($type->id, collect()) as $row) {
                $kinds[$row->getRawOriginal('kind')] = (int) $row->getAttribute('total');
            }

            $sum = fn (callable $filter) => array_sum(array_filter($kinds, fn (int $v, string $k) => $filter(LedgerKind::from($k)), ARRAY_FILTER_USE_BOTH));
            $balance = array_sum($kinds);
            $held = (int) ($pending[$type->id] ?? 0);

            $balances[] = [
                'type' => $type,
                'entitled' => $sum(fn (LedgerKind $k) => $k->isEntitlement()),
                'used' => -$sum(fn (LedgerKind $k) => $k->isUsage()),
                'adjusted' => $kinds[LedgerKind::Adjustment->value] ?? 0,
                'expired' => -($kinds[LedgerKind::Expiry->value] ?? 0),
                'balance' => $balance,
                'pending' => $held,
                'available' => $type->unlimited ? null : $balance - $held,
            ];
        }

        return $balances;
    }

    /**
     * @return Collection<int, LeaveLedgerEntry>
     */
    public function ledger(Employee $employee, int $leaveYear): Collection
    {
        return LeaveLedgerEntry::query()
            ->with(['type', 'creator'])
            ->where('employee_id', $employee->id)
            ->where('leave_year', $leaveYear)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * A manual credit (+) or debit (−) by HR, always with a reason.
     */
    public function adjust(Employee $employee, LeaveType $type, int $leaveYear, int $amountHalves, string $reason, User $actor): LeaveLedgerEntry
    {
        if ($type->unlimited) {
            throw new BusinessRuleException('Unlimited leave types keep no balance to adjust.', 'leave_unlimited');
        }

        return $this->ledger->write($employee->id, $type->id, $leaveYear, LedgerKind::Adjustment, $amountHalves, null, $reason, $actor->id)
            ->load(['type', 'creator']);
    }
}
