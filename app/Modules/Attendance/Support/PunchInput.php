<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Enums\PunchDirection;
use Carbon\CarbonImmutable;

/**
 * One punch as the calculator sees it: a matched raw punch, or the time of an
 * approved "add" correction. `ignored` marks a raw punch excluded by an
 * approved "ignore" correction (still shown, never counted).
 */
final readonly class PunchInput
{
    public function __construct(
        public CarbonImmutable $at,
        public ?int $rawPunchId = null,
        public ?int $correctionId = null,
        public ?PunchDirection $direction = null,
        public bool $ignored = false,
    ) {}

    public function timestamp(): int
    {
        return $this->at->getTimestamp();
    }
}
