<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Enums\LedgerKind;
use App\Modules\Leave\Models\LeaveLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of leave_ledger, and the only place balances are read
 * from. A balance is the sum of a year's entries; nothing is ever updated.
 */
final class LeaveLedger
{
    public function write(
        int $employeeId,
        int $typeId,
        int $leaveYear,
        LedgerKind $kind,
        int $amountHalves,
        ?int $requestId = null,
        ?string $reason = null,
        ?int $createdBy = null,
    ): LeaveLedgerEntry {
        return LeaveLedgerEntry::query()->create([
            'employee_id' => $employeeId,
            'leave_type_id' => $typeId,
            'leave_year' => $leaveYear,
            'kind' => $kind,
            'amount_halves' => $amountHalves,
            'leave_request_id' => $requestId,
            'reason' => $reason,
            'created_by' => $createdBy,
            'created_at' => CarbonImmutable::now('UTC'),
        ]);
    }

    /**
     * Writes a once-per-period entry (grant, accrual, carry-forward, expiry);
     * a second write for the same period is ignored by the unique index.
     */
    public function writeOnce(int $employeeId, int $typeId, int $leaveYear, LedgerKind $kind, int $amountHalves, string $periodKey): bool
    {
        return DB::table('leave_ledger')->insertOrIgnore([
            'employee_id' => $employeeId,
            'leave_type_id' => $typeId,
            'leave_year' => $leaveYear,
            'kind' => $kind->value,
            'amount_halves' => $amountHalves,
            'period_key' => $periodKey,
            'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
        ]) === 1;
    }

    public function balance(int $employeeId, int $typeId, int $leaveYear): int
    {
        return (int) LeaveLedgerEntry::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $typeId)
            ->where('leave_year', $leaveYear)
            ->sum('amount_halves');
    }

    /**
     * Half-days held by pending requests in a leave year (not yet debited).
     */
    public function pending(int $employeeId, int $typeId, int $leaveYear, ?int $exceptRequestId = null): int
    {
        return (int) DB::table('leave_request_days')
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_days.leave_request_id')
            ->where('leave_request_days.employee_id', $employeeId)
            ->where('leave_request_days.leave_year', $leaveYear)
            ->where('leave_requests.leave_type_id', $typeId)
            ->where('leave_requests.status', LeaveRequestStatus::Pending->value)
            ->whereNull('leave_requests.deleted_at')
            ->when($exceptRequestId !== null, fn ($q) => $q->where('leave_requests.id', '!=', $exceptRequestId))
            ->sum(DB::raw("case leave_request_days.portion when 'full' then 2 else 1 end"));
    }

    /**
     * Net half-days a request currently takes per leave year (its debits and
     * recounts, as a positive number).
     *
     * @return array<int, int>
     */
    public function taken(int $requestId): array
    {
        return LeaveLedgerEntry::query()
            ->where('leave_request_id', $requestId)
            ->whereIn('kind', [LedgerKind::Request->value, LedgerKind::Recount->value, LedgerKind::Cancellation->value])
            ->selectRaw('leave_year, sum(amount_halves) as total')
            ->groupBy('leave_year')
            ->pluck('total', 'leave_year')
            ->map(fn ($total) => -(int) $total)
            ->filter(fn (int $total) => $total !== 0)
            ->all();
    }
}
