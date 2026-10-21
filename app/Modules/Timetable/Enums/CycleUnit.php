<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Enums;

enum CycleUnit: string
{
    case Day = 'day';
    case Week = 'week';

    public function days(int $length): int
    {
        return $this === self::Week ? $length * 7 : $length;
    }

    public function maxLength(): int
    {
        return $this === self::Week ? 8 : 56;
    }
}
