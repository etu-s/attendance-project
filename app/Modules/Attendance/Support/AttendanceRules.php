<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use App\Modules\Core\Services\SettingsService;

/**
 * Workspace-wide calculation settings (per-timetable rules such as grace and
 * punch windows come from Timetable's TimetableRules).
 */
final readonly class AttendanceRules
{
    public function __construct(
        public int $debounceSeconds,
        public int $roundingMinutes,
        public bool $useDirection,
    ) {}

    public static function fromSettings(SettingsService $settings): self
    {
        return new self(
            (int) $settings->get('attendance.debounce_seconds'),
            (int) $settings->get('attendance.rounding_minutes'),
            (bool) $settings->get('attendance.use_punch_direction'),
        );
    }
}
