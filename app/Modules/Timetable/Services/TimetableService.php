<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Services;

use App\Modules\Core\Enums\AuditAction;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\Search;
use App\Modules\Timetable\Enums\BreakKind;
use App\Modules\Timetable\Enums\TimetableType;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Models\TimetableBreak;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Timetables and their breaks. Field formats are checked by the Form
 * Requests; the rules that span fields (breaks inside the timetable, required
 * minutes inside the window, core hours inside the window) are checked here so
 * they hold for every caller.
 */
final class TimetableService
{
    public const MAX_BREAKS = 4;

    public function __construct(
        private readonly UsageRegistry $usage,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, Timetable>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage): LengthAwarePaginator
    {
        $query = Timetable::query()
            ->with('breaks')
            ->withCount(['shiftDays as shifts_count' => fn (Builder $q) => $q->select(DB::raw('count(distinct shift_id)'))]);

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        Search::apply($query, ['name', 'code'], $search);

        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage)->withQueryString();
    }

    public function isLocked(Timetable $timetable): bool
    {
        return $this->usage->timetableInUse($timetable);
    }

    /**
     * @param  array<string, mixed>  $data  validated TimetableRequest input (incl. `breaks`)
     */
    public function create(array $data): Timetable
    {
        $breaks = $data['breaks'] ?? [];
        $attributes = $this->normalize(array_diff_key($data, ['breaks' => true]));
        $this->assertConsistent($attributes, $breaks);

        return DB::transaction(function () use ($attributes, $breaks): Timetable {
            $timetable = Timetable::query()->create($attributes);
            $this->writeBreaks($timetable, $breaks);

            if ($breaks !== []) {
                $this->audit->record($timetable, AuditAction::Updated, ['breaks' => []], ['breaks' => $this->snapshots($breaks)]);
            }

            return $timetable->load('breaks');
        });
    }

    /**
     * Partial update. Time rules (and breaks) of a timetable in use are
     * read-only: duplicate it and schedule the copy instead.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Timetable $timetable, array $data): Timetable
    {
        $timetable->loadMissing('breaks');
        $breaksGiven = array_key_exists('breaks', $data);
        $breaks = $breaksGiven ? $data['breaks'] : $timetable->breaks->map->snapshot()->all();

        $attributes = $this->normalize(array_merge(
            $timetable->only(array_merge(Timetable::TIME_RULES, ['name', 'code', 'color', 'is_active'])),
            array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, array_diff_key($data, ['breaks' => true])),
        ));
        $attributes['type'] = $attributes['type'] instanceof TimetableType ? $attributes['type']->value : $attributes['type'];
        $this->assertConsistent($attributes, $breaks);

        $timeRulesChanged = $this->timeRulesChanged($timetable, $attributes)
            || ($breaksGiven && $this->breaksChanged($timetable, $breaks));

        if ($timeRulesChanged && $this->isLocked($timetable)) {
            throw new BusinessRuleException(
                'This timetable is used by a schedule, so its times cannot change. Duplicate it and schedule the copy instead.',
                'timetable_locked',
                409,
            );
        }

        return DB::transaction(function () use ($timetable, $attributes, $breaks, $breaksGiven): Timetable {
            $timetable->update($attributes);

            if ($breaksGiven && $this->breaksChanged($timetable, $breaks)) {
                $before = $timetable->breaks->map->snapshot()->all();
                $this->writeBreaks($timetable, $breaks);
                $this->audit->record($timetable, AuditAction::Updated, ['breaks' => $before], ['breaks' => $this->snapshots($breaks)]);
            }

            return $timetable->refresh()->load('breaks');
        });
    }

    public function delete(Timetable $timetable): void
    {
        if ($timetable->shiftDays()->exists()) {
            throw new BusinessRuleException('This timetable is part of a shift. Remove it from the shift or deactivate it instead.', 'timetable_in_use', 409);
        }

        $timetable->delete();
    }

    /**
     * A copy with a fresh code and the same rules and breaks; it is not in use,
     * so its times can be edited. The way to change a timetable that is in use.
     */
    public function duplicate(Timetable $timetable): Timetable
    {
        $timetable->loadMissing('breaks');

        return DB::transaction(function () use ($timetable): Timetable {
            $copy = $timetable->replicate(['id', 'created_at', 'updated_at']);
            $copy->name = mb_substr($timetable->name.' (copy)', 0, 120);
            $copy->code = $this->freeCode(Timetable::query(), $timetable->code);
            $copy->save();

            $this->writeBreaks($copy, $timetable->breaks->map->snapshot()->all());

            return $copy->load('breaks');
        });
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public function freeCode(Builder $query, string $code): string
    {
        $base = mb_substr($code, 0, 24);
        for ($n = 2; ; $n++) {
            $candidate = "{$base}-{$n}";
            if (! (clone $query)->where('code', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    /**
     * Flexible-only columns are cleared on fixed timetables.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes): array
    {
        $type = $attributes['type'] ?? null;
        $type = $type instanceof TimetableType ? $type->value : $type;

        if ($type === TimetableType::Fixed->value) {
            $attributes['required_minutes'] = null;
            $attributes['core_start_offset'] = null;
            $attributes['core_duration_minutes'] = null;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $t
     * @param  list<array<string, mixed>>  $breaks
     */
    private function assertConsistent(array $t, array $breaks): void
    {
        $errors = [];
        $type = $t['type'] instanceof TimetableType ? $t['type'] : TimetableType::from((string) $t['type']);
        $duration = (int) $t['duration_minutes'];

        if ($type === TimetableType::Flexible) {
            $required = $t['required_minutes'] ?? null;
            if ($required === null) {
                $errors['required_minutes'] = 'Enter the minutes to work inside the window.';
            } elseif ((int) $required > $duration) {
                $errors['required_minutes'] = 'Required time cannot be longer than the window.';
            }

            $coreStart = $t['core_start_offset'] ?? null;
            $coreLength = $t['core_duration_minutes'] ?? null;
            if (($coreStart === null) !== ($coreLength === null)) {
                $errors['core_duration_minutes'] = 'Set both the start and the end of core hours, or neither.';
            } elseif ($coreStart !== null && (int) $coreStart + (int) $coreLength > $duration) {
                $errors['core_duration_minutes'] = 'Core hours must be inside the window.';
            }
        }

        foreach (['min_work_minutes', 'late_grace_minutes', 'early_leave_grace_minutes'] as $field) {
            if (isset($t[$field]) && (int) $t[$field] > $duration) {
                $errors[$field] = 'Cannot be longer than the timetable.';
            }
        }

        if (count($breaks) > self::MAX_BREAKS) {
            $errors['breaks'] = 'Use at most '.self::MAX_BREAKS.' breaks.';
        }

        $fixedRanges = [];
        $total = 0;
        foreach (array_values($breaks) as $i => $break) {
            $kind = $break['kind'] instanceof BreakKind ? $break['kind'] : BreakKind::from((string) $break['kind']);
            $length = (int) $break['duration_minutes'];
            $total += $length;

            if ($kind === BreakKind::Fixed) {
                if ($type === TimetableType::Flexible) {
                    $errors["breaks.{$i}.kind"] = 'Flexible timetables only have floating breaks.';

                    continue;
                }
                $offset = $break['start_offset'] ?? null;
                if ($offset === null) {
                    $errors["breaks.{$i}.start_offset"] = 'Set when the break starts.';

                    continue;
                }
                if ((int) $offset < 1 || (int) $offset + $length >= $duration) {
                    $errors["breaks.{$i}.start_offset"] = 'The break must be inside the timetable.';

                    continue;
                }
                foreach ($fixedRanges as $j => [$from, $to]) {
                    if ((int) $offset < $to && (int) $offset + $length > $from) {
                        $errors["breaks.{$i}.start_offset"] = 'Overlaps break '.($j + 1).'.';
                    }
                }
                $fixedRanges[$i] = [(int) $offset, (int) $offset + $length];
            }
        }

        $available = $type === TimetableType::Flexible ? $duration - (int) ($t['required_minutes'] ?? 0) : $duration - 1;
        if ($breaks !== [] && $total > $available) {
            $errors['breaks'] = $type === TimetableType::Flexible
                ? 'Breaks plus required time do not fit in the window.'
                : 'Breaks are longer than the timetable.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function timeRulesChanged(Timetable $timetable, array $attributes): bool
    {
        foreach (Timetable::TIME_RULES as $field) {
            $current = $timetable->{$field};
            $current = $current instanceof \BackedEnum ? $current->value : $current;
            $next = $attributes[$field] ?? null;
            $next = $next instanceof \BackedEnum ? $next->value : $next;

            if ((string) $current !== (string) $next) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $breaks
     */
    private function breaksChanged(Timetable $timetable, array $breaks): bool
    {
        return $timetable->breaks->map->snapshot()->all() !== $this->snapshots($breaks);
    }

    /**
     * @param  list<array<string, mixed>>  $breaks
     * @return list<array{kind: string, start_offset: int|null, duration_minutes: int, is_paid: bool}>
     */
    private function snapshots(array $breaks): array
    {
        return array_values(array_map(function (array $b): array {
            $kind = $b['kind'] instanceof BreakKind ? $b['kind']->value : (string) $b['kind'];

            return [
                'kind' => $kind,
                'start_offset' => $kind === BreakKind::Fixed->value ? (int) $b['start_offset'] : null,
                'duration_minutes' => (int) $b['duration_minutes'],
                'is_paid' => (bool) ($b['is_paid'] ?? false),
            ];
        }, $breaks));
    }

    /**
     * @param  list<array<string, mixed>>  $breaks
     */
    private function writeBreaks(Timetable $timetable, array $breaks): void
    {
        $timetable->breaks()->delete();

        foreach ($this->snapshots($breaks) as $position => $break) {
            TimetableBreak::query()->create($break + ['timetable_id' => $timetable->id, 'position' => $position]);
        }

        $timetable->unsetRelation('breaks');
    }
}
