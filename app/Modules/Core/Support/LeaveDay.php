<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Enums\DayPortion;

/**
 * Approved leave on one work date, as other modules (attendance, overtime,
 * reports) see it through the LeaveCalendar contract.
 */
final readonly class LeaveDay
{
    public function __construct(
        public string $date,
        public DayPortion $portion,
        public string $typeName,
        public string $typeCode,
        public bool $paid,
        public int $requestId,
    ) {}
}
