<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Contracts\PeriodLock;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\EmployeeVisibility;
use App\Modules\Scheduling\Enums\ScheduleTarget;
use App\Modules\Scheduling\Events\ScheduleChanged;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Scheduling\Models\TemporarySchedule;
use App\Modules\Timetable\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dated shift assignments per target. Periods of one target never overlap.
 *
 * assign() semantics:
 *  - With an end date, the new period overwrites that range: periods inside
 *    it are removed, periods crossing its edges are trimmed, and a period
 *    spanning the whole range is split around it.
 *  - Without an end date, the new period runs until the next planned change
 *    (or the end of the period it starts in), so future plans survive.
 * Every change fires ScheduleChanged so attendance can be recalculated.
 */
final class ScheduleAssignmentService
{
    public function __construct(
        private readonly ScheduleTargets $targets,
        private readonly EmployeeVisibility $visibility,
        private readonly TenantClock $clock,
        private readonly PeriodLock $locks,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, ScheduleAssignment>
     */
    public function paginate(array $filters, array $sort, int $perPage, User $viewer): LengthAwarePaginator
    {
        $query = ScheduleAssignment::query()->with('shift');

        foreach (['target_type', 'target_id', 'shift_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (isset($filters['active_on'])) {
            $day = (string) $filters['active_on'];
            $query->where('effective_from', '<=', $day)
                ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day));
        }

        // Employee-level schedules are personal: managers only see their people's.
        if (! $this->visibility->seesEveryone($viewer)) {
            $visible = Employee::query()->select('employees.id');
            $this->visibility->apply($visible, $viewer);
            $query->where(fn (Builder $q) => $q
                ->where('target_type', '!=', ScheduleTarget::Employee->value)
                ->orWhereIn('target_id', $visible));
        }

        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage)->withQueryString();
    }

    public function assign(
        ScheduleTarget $type,
        ?int $targetId,
        int $shiftId,
        CarbonImmutable $from,
        ?CarbonImmutable $to = null,
        ?CarbonImmutable $anchor = null,
    ): ScheduleAssignment {
        $this->targets->assertExists($type, $targetId);
        $this->assertActiveShift($shiftId);

        if ($to !== null && $to->lessThan($from)) {
            throw ValidationException::withMessages(['effective_to' => 'The end date is before the start date.']);
        }

        $assignment = DB::transaction(function () use ($type, $targetId, $shiftId, $from, $to, $anchor): ScheduleAssignment {
            $existing = $this->periods($type, $targetId);

            if ($to === null) {
                // Starting inside a period: take over its remainder (a planned gap after it stays).
                // Starting in a gap: run until the next planned period.
                $covering = $existing->first(fn (ScheduleAssignment $a) => $a->covers($from));
                $next = $existing->first(fn (ScheduleAssignment $a) => $a->effective_from->greaterThan($from));
                $to = $covering !== null ? $covering->effective_to : $next?->effective_from->subDay();
            }

            $this->locks->assertOpen($from, $to, 'This schedule change');
            $this->clearRange($existing, $from, $to);

            return ScheduleAssignment::query()->create([
                'target_type' => $type,
                'target_id' => $targetId,
                'shift_id' => $shiftId,
                'anchor_date' => $anchor ?? $from,
                'effective_from' => $from,
                'effective_to' => $to,
            ]);
        });

        $this->changed($assignment, $assignment->effective_from, $assignment->effective_to);

        return $assignment->load('shift');
    }

    /**
     * Shift, cycle anchor and end date can change; start date and target are
     * fixed (assign a new period instead). Past ranges may be corrected; the
     * change is audited and announced for recalculation.
     *
     * @param  array{shift_id?: int, anchor_date?: string, effective_to?: string|null}  $data
     */
    public function update(ScheduleAssignment $assignment, array $data): ScheduleAssignment
    {
        if (isset($data['shift_id'])) {
            $this->assertActiveShift((int) $data['shift_id']);
        }

        $oldTo = $assignment->effective_to;

        if (array_key_exists('effective_to', $data)) {
            $to = $data['effective_to'] === null ? null : LocalDate::parse((string) $data['effective_to']);

            if ($to !== null && $to->lessThan($assignment->effective_from)) {
                throw ValidationException::withMessages(['effective_to' => 'The end date is before the start date.']);
            }

            $next = $this->periods($assignment->target_type, $assignment->target_id)
                ->first(fn (ScheduleAssignment $a) => $a->id !== $assignment->id && $a->effective_from->greaterThan($assignment->effective_from));

            if ($next !== null && ($to === null || $to->greaterThanOrEqualTo($next->effective_from))) {
                throw ValidationException::withMessages(['effective_to' => 'Must end before the next period, which starts '.$next->effective_from->toDateString().'.']);
            }
        }

        $this->assertUpdateOpen($assignment, $data);
        $assignment->update($data);

        // The affected range is the union of the old and new extents.
        $end = $oldTo === null || $assignment->effective_to === null ? null : max($oldTo, $assignment->effective_to);
        $this->changed($assignment, $assignment->effective_from, $end);

        return $assignment->load('shift');
    }

    public function delete(ScheduleAssignment $assignment): void
    {
        $this->locks->assertOpen($assignment->effective_from, $assignment->effective_to, 'Removing this schedule');
        $assignment->delete();
        $this->changed($assignment, $assignment->effective_from, $assignment->effective_to);
    }

    /**
     * Has a schedule using this shift already started? Then its time rules are
     * read-only (registered with the Timetable module's UsageRegistry).
     * Future-only use does not lock: no attendance has been evaluated yet.
     */
    public function shiftInUse(int $shiftId): bool
    {
        $today = $this->clock->today()->format(LocalDate::FORMAT);

        return ScheduleAssignment::query()->where('shift_id', $shiftId)->where('effective_from', '<=', $today)->exists()
            || TemporarySchedule::query()->where('shift_id', $shiftId)->where('date_from', '<=', $today)->exists();
    }

    /**
     * @return Collection<int, ScheduleAssignment>
     */
    private function periods(ScheduleTarget $type, ?int $targetId): Collection
    {
        return ScheduleAssignment::query()
            ->where('target_type', $type)
            ->when($targetId === null, fn (Builder $q) => $q->whereNull('target_id'), fn (Builder $q) => $q->where('target_id', $targetId))
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * Removes [$from, $to] from existing periods (trim, split or delete).
     *
     * @param  Collection<int, ScheduleAssignment>  $existing
     */
    private function clearRange(Collection $existing, CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        foreach ($existing as $period) {
            $startsBefore = $period->effective_from->lessThan($from);
            $endsAfter = $to !== null && ($period->effective_to === null || $period->effective_to->greaterThan($to));
            $overlaps = ($period->effective_to === null || $period->effective_to->greaterThanOrEqualTo($from))
                && ($to === null || $period->effective_from->lessThanOrEqualTo($to));

            if (! $overlaps) {
                continue;
            }

            if ($startsBefore && $endsAfter) {
                // Split: keep the head, re-create the tail after the new range.
                /** @var CarbonImmutable $to */
                ScheduleAssignment::query()->create([
                    'target_type' => $period->target_type,
                    'target_id' => $period->target_id,
                    'shift_id' => $period->shift_id,
                    'anchor_date' => $period->anchor_date,
                    'effective_from' => $to->addDay(),
                    'effective_to' => $period->effective_to,
                ]);
                $period->update(['effective_to' => $from->subDay()]);
            } elseif ($startsBefore) {
                $period->update(['effective_to' => $from->subDay()]);
            } elseif ($endsAfter) {
                /** @var CarbonImmutable $to */
                $period->update(['effective_from' => $to->addDay()]);
            } else {
                $period->delete();
            }
        }
    }

    /**
     * The days an update really changes must not be in a locked payroll
     * period: the whole period when the shift or anchor changes, otherwise
     * only the days between the old and the new end (null = open-ended).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertUpdateOpen(ScheduleAssignment $assignment, array $data): void
    {
        $oldTo = $assignment->effective_to;
        $newTo = array_key_exists('effective_to', $data)
            ? ($data['effective_to'] === null ? null : LocalDate::parse((string) $data['effective_to']))
            : $oldTo;
        $shiftChanges = isset($data['shift_id']) && (int) $data['shift_id'] !== $assignment->shift_id;
        $anchorChanges = isset($data['anchor_date']) && ! LocalDate::parse((string) $data['anchor_date'])->equalTo($assignment->anchor_date);

        if ($shiftChanges || $anchorChanges) {
            $end = $oldTo === null || $newTo === null ? null : $oldTo->max($newTo);
            $this->locks->assertOpen($assignment->effective_from, $end, 'This schedule change');

            return;
        }

        $sameEnd = ($oldTo === null && $newTo === null) || ($oldTo !== null && $newTo !== null && $oldTo->equalTo($newTo));
        if (! $sameEnd) {
            $earlier = $oldTo === null ? $newTo : ($newTo === null ? $oldTo : $oldTo->min($newTo));
            $later = $oldTo === null || $newTo === null ? null : $oldTo->max($newTo);
            $this->locks->assertOpen($earlier->addDay(), $later, 'This schedule change');
        }
    }

    private function assertActiveShift(int $shiftId): void
    {
        if (! Shift::query()->whereKey($shiftId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['shift_id' => 'Choose an active shift.']);
        }
    }

    private function changed(ScheduleAssignment $assignment, CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        event(new ScheduleChanged(
            $assignment->target_type->value,
            $assignment->target_id,
            $from->format(LocalDate::FORMAT),
            $to?->format(LocalDate::FORMAT),
        ));
    }
}
