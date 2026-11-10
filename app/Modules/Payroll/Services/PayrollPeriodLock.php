<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Contracts\PeriodLock;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Core's PeriodLock, backed by locked payroll_periods rows. Not cached: a
 * queue worker must see a lock or unlock made a moment ago.
 */
final class PayrollPeriodLock implements PeriodLock
{
    public function lockedDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];
        foreach ($this->query($from, $to)->get(['start_date', 'end_date']) as $period) {
            $start = $period->start_date->max($from);
            $end = $period->end_date->min($to);
            for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
                $dates[$date->format(LocalDate::FORMAT)] = true;
            }
        }

        return $dates;
    }

    public function assertOpen(CarbonImmutable $from, ?CarbonImmutable $to, string $what = 'This change'): void
    {
        $period = $this->query($from, $to)->first();

        if ($period !== null) {
            throw new BusinessRuleException(
                "{$what} falls in the locked payroll period \"{$period->name}\" ({$period->start_date->format(LocalDate::FORMAT)} – {$period->end_date->format(LocalDate::FORMAT)}). A Tenant Admin can unlock it.",
                'period_locked',
                409,
            );
        }
    }

    public function overlapping(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->query($from, $to)->get()->map(fn (PayrollPeriod $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'start_date' => $p->start_date->format(LocalDate::FORMAT),
            'end_date' => $p->end_date->format(LocalDate::FORMAT),
        ])->values()->all();
    }

    /**
     * Locked periods overlapping [from, to] (to = null: from onwards).
     *
     * @return Builder<PayrollPeriod>
     */
    private function query(CarbonImmutable $from, ?CarbonImmutable $to): Builder
    {
        return PayrollPeriod::query()
            ->where('status', PayrollPeriodStatus::Locked->value)
            ->where('end_date', '>=', $from->format(LocalDate::FORMAT))
            ->when($to !== null, fn (Builder $q) => $q->where('start_date', '<=', $to->format(LocalDate::FORMAT)))
            ->orderBy('start_date');
    }
}
