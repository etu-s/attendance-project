<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Services;

use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * "Is this shift or timetable used by something that depends on its time
 * rules?" Modules that reference shifts (Scheduling) register a checker; this
 * module then refuses changes to time rules of anything in use, so days that
 * were already evaluated keep the rules they were evaluated with.
 *
 * A timetable is in use when any shift containing it is in use, or when a
 * checker registered with whenTimetableInUse() says so.
 */
final class UsageRegistry
{
    /** @var list<Closure(int): bool> */
    private array $shiftCheckers = [];

    /** @var list<Closure(int): bool> */
    private array $timetableCheckers = [];

    /**
     * @param  Closure(int $shiftId): bool  $checker
     */
    public function whenShiftInUse(Closure $checker): void
    {
        $this->shiftCheckers[] = $checker;
    }

    /**
     * @param  Closure(int $timetableId): bool  $checker
     */
    public function whenTimetableInUse(Closure $checker): void
    {
        $this->timetableCheckers[] = $checker;
    }

    public function shiftInUse(Shift|int $shift): bool
    {
        $id = $shift instanceof Shift ? $shift->id : $shift;

        foreach ($this->shiftCheckers as $checker) {
            if ($checker($id)) {
                return true;
            }
        }

        return false;
    }

    public function timetableInUse(Timetable|int $timetable): bool
    {
        $id = $timetable instanceof Timetable ? $timetable->id : $timetable;

        foreach ($this->timetableCheckers as $checker) {
            if ($checker($id)) {
                return true;
            }
        }

        if ($this->shiftCheckers === []) {
            return false;
        }

        $shiftIds = DB::table('shift_days')->where('timetable_id', $id)->distinct()->pluck('shift_id');

        return $shiftIds->contains(fn ($shiftId) => $this->shiftInUse((int) $shiftId));
    }
}
