<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Services;

use App\Modules\Core\Enums\AuditAction;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\Search;
use App\Modules\Timetable\Enums\CycleUnit;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\ShiftDay;
use App\Modules\Timetable\Models\Timetable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ShiftService
{
    public function __construct(
        private readonly UsageRegistry $usage,
        private readonly AuditLogger $audit,
        private readonly TimetableService $timetables,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, Shift>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage): LengthAwarePaginator
    {
        $query = Shift::query()->with('days.timetable');

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        Search::apply($query, ['name', 'code'], $search);

        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage)->withQueryString();
    }

    public function isLocked(Shift $shift): bool
    {
        return $this->usage->shiftInUse($shift);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Shift
    {
        return Shift::query()->create($attributes)->load('days.timetable');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Shift $shift, array $attributes): Shift
    {
        $unit = isset($attributes['cycle_unit']) ? CycleUnit::from((string) $attributes['cycle_unit']) : $shift->cycle_unit;
        $length = (int) ($attributes['cycle_length'] ?? $shift->cycle_length);
        $cycleChanged = $unit !== $shift->cycle_unit || $length !== $shift->cycle_length;

        if ($cycleChanged) {
            if ($this->isLocked($shift)) {
                throw $this->locked();
            }

            if ($shift->days()->where('day_index', '>=', $unit->days($length))->exists()) {
                throw ValidationException::withMessages(['cycle_length' => 'Some working days fall outside the shorter cycle. Clear them first.']);
            }
        }

        $shift->update($attributes);

        return $shift->load('days.timetable');
    }

    public function delete(Shift $shift): void
    {
        if ($this->isLocked($shift)) {
            throw new BusinessRuleException('This shift is used by a schedule. Deactivate it instead.', 'shift_in_use', 409);
        }

        $shift->delete();
    }

    public function duplicate(Shift $shift): Shift
    {
        $shift->loadMissing('days');

        return DB::transaction(function () use ($shift): Shift {
            $copy = $shift->replicate(['id', 'created_at', 'updated_at']);
            $copy->name = mb_substr($shift->name.' (copy)', 0, 120);
            $copy->code = $this->timetables->freeCode(Shift::query(), $shift->code);
            $copy->save();

            foreach ($shift->days as $day) {
                ShiftDay::query()->create(['shift_id' => $copy->id, 'day_index' => $day->day_index, 'timetable_id' => $day->timetable_id]);
            }

            return $copy->load('days.timetable');
        });
    }

    /**
     * Replaces the whole cycle. Days not listed are rest days.
     *
     * @param  list<array{day_index: int, timetable_ids: list<int>}>  $days
     */
    public function setDays(Shift $shift, array $days): Shift
    {
        if ($this->isLocked($shift)) {
            throw $this->locked();
        }

        $byDay = [];
        foreach ($days as $day) {
            $ids = array_values(array_unique(array_map('intval', $day['timetable_ids'])));
            if ($ids !== []) {
                $byDay[(int) $day['day_index']] = $ids;
            }
        }
        ksort($byDay);

        $timetables = Timetable::query()->whereKey(array_merge([], ...array_values($byDay)))->get()->keyBy('id');
        $this->assertValidCycle($shift, $byDay, $timetables);

        return DB::transaction(function () use ($shift, $byDay): Shift {
            $before = $this->cycleSnapshot($shift);

            $shift->days()->delete();
            foreach ($byDay as $index => $ids) {
                foreach ($ids as $id) {
                    ShiftDay::query()->create(['shift_id' => $shift->id, 'day_index' => $index, 'timetable_id' => $id]);
                }
            }

            $after = $this->cycleSnapshot($shift);
            if ($before !== $after) {
                $this->audit->record($shift, AuditAction::Updated, ['days' => $before], ['days' => $after]);
            }

            return $shift->load('days.timetable');
        });
    }

    /**
     * @param  array<int, list<int>>  $byDay
     * @param  Collection<int, Timetable>  $timetables
     */
    private function assertValidCycle(Shift $shift, array $byDay, Collection $timetables): void
    {
        $cycleDays = $shift->cycleDays();
        $errors = [];
        $periods = [];

        foreach ($byDay as $index => $ids) {
            if ($index < 0 || $index >= $cycleDays) {
                $errors["days.{$index}"] = 'Day '.($index + 1)." is outside the {$cycleDays}-day cycle.";

                continue;
            }

            if (count($ids) > Shift::MAX_TIMETABLES_PER_DAY) {
                $errors["days.{$index}"] = 'Use at most '.Shift::MAX_TIMETABLES_PER_DAY.' timetables per day.';
            }

            foreach ($ids as $id) {
                $timetable = $timetables->get($id);
                if ($timetable === null || ! $timetable->is_active) {
                    $errors["days.{$index}"] = 'Choose active timetables.';

                    continue;
                }

                $start = $index * 1440 + $timetable->start_minute;
                $periods[] = [$start, $start + $timetable->duration_minutes, $index, $timetable->name];
            }
        }

        // The cycle repeats: last-day periods can run into day 1 of the next cycle.
        $wrapped = array_map(fn (array $p) => [$p[0] + $cycleDays * 1440, $p[1] + $cycleDays * 1440, $p[2], $p[3]], $periods);
        $all = array_merge($periods, $wrapped);
        usort($all, fn (array $a, array $b) => $a[0] <=> $b[0]);

        for ($i = 1, $n = count($all); $i < $n; $i++) {
            [$start, , $day, $name] = $all[$i];
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($all[$j][1] > $start) {
                    $errors["days.{$day}"] ??= "{$name} on day ".($day + 1)." overlaps {$all[$j][3]} (day ".($all[$j][2] + 1).').';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<int, list<int>> day index => timetable ids
     */
    private function cycleSnapshot(Shift $shift): array
    {
        return $shift->days()->get()
            ->groupBy('day_index')
            ->map(fn (Collection $days) => $days->pluck('timetable_id')->sort()->values()->all())
            ->all();
    }

    private function locked(): BusinessRuleException
    {
        return new BusinessRuleException(
            'This shift is used by a schedule, so its cycle cannot change. Duplicate it and schedule the copy instead.',
            'shift_locked',
            409,
        );
    }
}
