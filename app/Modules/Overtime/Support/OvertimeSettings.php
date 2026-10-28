<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Support;

use App\Modules\Core\Services\SettingsService;

/**
 * The workspace's overtime rules (Settings → Overtime). Rates are whole
 * percentages: 150 = time and a half.
 */
final readonly class OvertimeSettings
{
    public function __construct(
        public int $minMinutes,
        public int $roundingMinutes,
        public int $dailyCapMinutes,
        public bool $countEarlyArrival,
        public int $rateWorkday,
        public int $rateRestDay,
        public int $rateHoliday,
    ) {}

    public static function fromSettings(SettingsService $settings): self
    {
        return new self(
            (int) $settings->get('overtime.min_minutes'),
            (int) $settings->get('overtime.rounding_minutes'),
            (int) $settings->get('overtime.daily_cap_minutes'),
            (bool) $settings->get('overtime.count_early_arrival'),
            (int) $settings->get('overtime.rate_workday'),
            (int) $settings->get('overtime.rate_rest_day'),
            (int) $settings->get('overtime.rate_holiday'),
        );
    }

    /**
     * @param  array{before: int, after: int, rest_day: int, holiday: int}  $minutes
     */
    public function weighted(array $minutes): int
    {
        return intdiv(
            ($minutes['before'] + $minutes['after']) * $this->rateWorkday
            + $minutes['rest_day'] * $this->rateRestDay
            + $minutes['holiday'] * $this->rateHoliday,
            100,
        );
    }
}
