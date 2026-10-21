<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The resolved schedule changed for a scope from $from to $to (inclusive,
 * Y-m-d; $to null = open-ended). Fired after the change is committed. The
 * attendance engine (Module 5) listens to recalculate the affected days.
 *
 * Scope: organization (id null, everyone), department, group, employee or
 * location (holidays at one location).
 */
final readonly class ScheduleChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $scopeType,
        public ?int $scopeId,
        public string $from,
        public ?string $to,
    ) {}
}
