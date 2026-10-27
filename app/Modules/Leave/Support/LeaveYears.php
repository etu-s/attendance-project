<?php

declare(strict_types=1);

namespace App\Modules\Leave\Support;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Services\SettingsService;
use Carbon\CarbonImmutable;

/**
 * The leave year starts on the first day of `leave.year_start_month` and is
 * named after the calendar year it starts in (April start: 2026 = 2026-04-01
 * to 2027-03-31).
 */
final class LeaveYears
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function startMonth(): int
    {
        return max(1, min(12, (int) $this->settings->get('leave.year_start_month')));
    }

    public function of(CarbonImmutable $date): int
    {
        return $date->month >= $this->startMonth() ? $date->year : $date->year - 1;
    }

    public function start(int $year): CarbonImmutable
    {
        return LocalDate::parse(sprintf('%04d-%02d-01', $year, $this->startMonth()));
    }

    public function end(int $year): CarbonImmutable
    {
        return $this->start($year + 1)->subDay();
    }

    /**
     * Month number within the leave year (1–12) of a date.
     */
    public function monthIndex(CarbonImmutable $date): int
    {
        return (($date->month - $this->startMonth() + 12) % 12) + 1;
    }
}
