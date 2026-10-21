<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Services;

use App\Modules\Core\Services\SettingsService;
use App\Modules\Timetable\Models\Timetable;

/**
 * Grace minutes and punch windows that apply to a timetable: its own value,
 * or the workspace default (attendance.* settings) when it has none. The
 * attendance engine (Module 5) must read thresholds only through here.
 */
final class TimetableRules
{
    /** timetable column => setting key */
    public const DEFAULTS = [
        'late_grace_minutes' => 'attendance.late_grace_minutes',
        'early_leave_grace_minutes' => 'attendance.early_leave_grace_minutes',
        'check_in_before_minutes' => 'attendance.check_in_before_minutes',
        'check_out_after_minutes' => 'attendance.check_out_after_minutes',
    ];

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{late_grace_minutes: int, early_leave_grace_minutes: int, check_in_before_minutes: int, check_out_after_minutes: int}
     */
    public function for(Timetable $timetable): array
    {
        $rules = [];

        foreach (self::DEFAULTS as $column => $setting) {
            $rules[$column] = $timetable->{$column} ?? (int) $this->settings->get($setting);
        }

        /** @var array{late_grace_minutes: int, early_leave_grace_minutes: int, check_in_before_minutes: int, check_out_after_minutes: int} $rules */
        return $rules;
    }
}
