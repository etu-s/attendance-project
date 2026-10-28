<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\PunchSourceKind;
use App\Modules\Attendance\Models\PunchSource;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Organization\Models\Employee;
use App\Modules\Scheduling\Services\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Generates realistic punches from each employee's real schedule (local
 * development and demos): mostly on time, some late, some early leavers,
 * some staying late (overtime), missed punches, absences, double taps, and
 * one unknown badge so the
 * unmatched-punch flow has something to show. Seeded, so a run can be
 * repeated exactly; repeating it creates only duplicates.
 */
final class PunchSimulator
{
    public const SOURCE_NAME = 'Simulator';

    public const UNKNOWN_BADGE = 'BADGE-0000';

    public function __construct(
        private readonly ScheduleResolver $resolver,
        private readonly PunchSourceService $sources,
    ) {}

    /**
     * The simulator's own source (created on first use; its key is never shown,
     * the simulator ingests directly).
     */
    public function source(): PunchSource
    {
        return PunchSource::query()->where('name', self::SOURCE_NAME)->where('kind', PunchSourceKind::Integration->value)->first()
            ?? $this->sources->create(['name' => self::SOURCE_NAME, 'kind' => PunchSourceKind::Integration->value])[0];
    }

    /**
     * @return list<array{employee_identifier: string, punched_at: string, idempotency_key: string}>
     */
    public function generate(CarbonImmutable $from, CarbonImmutable $to, float $lateRate, float $missingRate, int $seed, float $overtimeRate = 0.1): array
    {
        mt_srand($seed);
        $now = CarbonImmutable::now('UTC');
        $punches = [];

        $employees = Employee::query()
            ->where('hire_date', '<=', $to->format(LocalDate::FORMAT))
            ->where(fn (Builder $q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $from->format(LocalDate::FORMAT)))
            ->orderBy('id')
            ->get();

        foreach ($employees->chunk(50) as $chunk) {
            $resolved = $this->resolver->forEmployees($chunk, $from, $to);

            foreach ($chunk as $employee) {
                foreach ($resolved[$employee->id] as $date => $day) {
                    if (! $day->isWorkingDay() || $this->chance($missingRate / 2)) {
                        continue;
                    }

                    foreach ($day->periods as $index => $period) {
                        $in = $this->chance($lateRate)
                            ? $period->startsAt->addMinutes(mt_rand(6, 40))
                            : $period->startsAt->subMinutes(mt_rand(0, 20));
                        $out = $this->chance($lateRate / 2)
                            ? $period->endsAt->subMinutes(mt_rand(10, 60))
                            : ($this->chance($overtimeRate)
                                ? $period->endsAt->addMinutes(mt_rand(35, 120))
                                : $period->endsAt->addMinutes(mt_rand(0, 25)));

                        $in = $in->addSeconds(mt_rand(0, 59));
                        $times = [$in, $out->addSeconds(mt_rand(0, 59))];
                        if ($this->chance($missingRate)) {
                            unset($times[mt_rand(0, 1)]);
                        } elseif ($this->chance(0.05)) {
                            // A double tap, inside the debounce window.
                            $times[] = $in->addSeconds(mt_rand(10, 30));
                        }

                        foreach ($times as $n => $at) {
                            if ($at->greaterThan($now)) {
                                continue;
                            }
                            $punches[] = [
                                'employee_identifier' => $employee->employee_code,
                                'punched_at' => $at->utc()->format('Y-m-d\TH:i:s\Z'),
                                'idempotency_key' => "sim-{$seed}-{$employee->id}-{$date}-{$index}-{$n}",
                            ];
                        }
                    }
                }
            }
        }

        $badge = $from->setTime(9, 3);
        if ($badge->lessThanOrEqualTo($now)) {
            $punches[] = [
                'employee_identifier' => self::UNKNOWN_BADGE,
                'punched_at' => $badge->format('Y-m-d\TH:i:s\Z'),
                'idempotency_key' => "sim-{$seed}-unknown-badge",
            ];
        }

        return $punches;
    }

    private function chance(float $rate): bool
    {
        return mt_rand() / mt_getrandmax() < $rate;
    }
}
