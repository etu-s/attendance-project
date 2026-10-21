<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Enums\PunchRole;

/**
 * A punch placed on a day, with its role and (if any) period index.
 */
final readonly class PunchUse
{
    public function __construct(
        public PunchInput $punch,
        public PunchRole $role,
        public ?int $periodIndex = null,
    ) {}
}
