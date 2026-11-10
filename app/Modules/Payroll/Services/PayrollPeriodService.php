<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Events\PayrollPeriodUnlocked;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Locking and unlocking payroll periods (approved for Module 9):
 *  - only finished days can be locked (the period ends before today), at
 *    most 366 days, and locked periods never overlap;
 *  - HR and Tenant Admins lock (and lock again); only Tenant Admins unlock,
 *    with a reason;
 *  - while locked, the days are frozen: the attendance engine skips them and
 *    changes that touch them are refused with `409 period_locked`;
 *  - unlocking announces the days (PayrollPeriodUnlocked) so attendance
 *    recalculates them.
 */
final class PayrollPeriodService
{
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly TenantClock $clock,
    ) {}

    /**
     * @return LengthAwarePaginator<int, PayrollPeriod>
     */
    public function paginate(?string $status, int $perPage): LengthAwarePaginator
    {
        return PayrollPeriod::query()
            ->with(['locker', 'unlocker'])
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array{name: string, start_date: string, end_date: string}  $data
     */
    public function lock(array $data, User $actor): PayrollPeriod
    {
        $start = LocalDate::parse($data['start_date']);
        $end = LocalDate::parse($data['end_date']);
        $this->assertLockable($start, $end);

        return DB::transaction(function () use ($data, $start, $end, $actor): PayrollPeriod {
            $this->assertNoOverlap($start, $end, null);

            return PayrollPeriod::query()->create([
                'name' => trim($data['name']),
                'start_date' => $start,
                'end_date' => $end,
                'status' => PayrollPeriodStatus::Locked,
                'locked_by' => $actor->id,
                'locked_at' => CarbonImmutable::now('UTC'),
            ])->load(['locker', 'unlocker']);
        });
    }

    public function relock(PayrollPeriod $period, User $actor): PayrollPeriod
    {
        if ($period->isLocked()) {
            throw new BusinessRuleException('This payroll period is already locked.', 'period_already_locked', 409);
        }
        $this->assertLockable($period->start_date, $period->end_date);

        return DB::transaction(function () use ($period, $actor): PayrollPeriod {
            $this->assertNoOverlap($period->start_date, $period->end_date, $period->id);

            $period->update([
                'status' => PayrollPeriodStatus::Locked,
                'locked_by' => $actor->id,
                'locked_at' => CarbonImmutable::now('UTC'),
                'unlocked_by' => null,
                'unlocked_at' => null,
                'unlock_reason' => null,
            ]);

            return $period->load(['locker', 'unlocker']);
        });
    }

    public function unlock(PayrollPeriod $period, User $actor, string $reason): PayrollPeriod
    {
        if (! $period->isLocked()) {
            throw new BusinessRuleException('This payroll period is not locked.', 'period_not_locked', 409);
        }

        $period->update([
            'status' => PayrollPeriodStatus::Unlocked,
            'unlocked_by' => $actor->id,
            'unlocked_at' => CarbonImmutable::now('UTC'),
            'unlock_reason' => trim($reason),
        ]);

        event(new PayrollPeriodUnlocked($period->start_date->format(LocalDate::FORMAT), $period->end_date->format(LocalDate::FORMAT)));

        return $period->load(['locker', 'unlocker']);
    }

    private function assertLockable(CarbonImmutable $start, CarbonImmutable $end): void
    {
        $today = $this->clock->today();

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages(['end_date' => 'The end date is before the start date.']);
        }
        if ($end->greaterThanOrEqualTo($today)) {
            // A frozen day that has not finished would never be calculated.
            throw ValidationException::withMessages(['end_date' => 'Only finished days can be locked: end the period before today.']);
        }
        if ($start->diffInDays($end) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['end_date' => 'A payroll period is at most '.self::MAX_DAYS.' days.']);
        }
    }

    private function assertNoOverlap(CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreId): void
    {
        $other = PayrollPeriod::query()
            ->where('status', PayrollPeriodStatus::Locked->value)
            ->where('start_date', '<=', $end->format(LocalDate::FORMAT))
            ->where('end_date', '>=', $start->format(LocalDate::FORMAT))
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();

        if ($other !== null) {
            throw new BusinessRuleException("This overlaps the locked payroll period \"{$other->name}\".", 'period_overlap', 409);
        }
    }
}
