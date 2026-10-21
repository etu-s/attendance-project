<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\MatchedBy;
use App\Modules\Attendance\Enums\OrphanStatus;
use App\Modules\Attendance\Models\EmployeePunchIdentifier;
use App\Modules\Attendance\Models\RawPunch;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns newly received raw punches into matches (or orphans) and recalculates
 * the affected days. Raw punches cannot be flagged as processed, so progress
 * is a cursor (attendance_engine_state.last_raw_punch_id).
 *
 * Runs inside ProcessPunches only, which never overlaps itself per tenant:
 * one tenant's punches are processed strictly in order, by one writer.
 */
final class PunchProcessor
{
    public const BATCH = 500;

    /** Recalculate one range for the whole batch when its punches span at most this many days. */
    private const SHARED_RANGE_DAYS = 7;

    public function __construct(
        private readonly AttendanceCalculator $calculator,
    ) {}

    /**
     * @return int raw punches processed
     */
    public function processPending(): int
    {
        $total = 0;

        while (true) {
            [$count, $affected] = DB::transaction(fn (): array => $this->matchNextBatch());

            if ($count === 0) {
                return $total;
            }

            $total += $count;
            $this->recalculate($affected);
        }
    }

    /**
     * Matches the given raw punches to an employee (linking orphan punches).
     * Punches already matched are left as they are.
     *
     * @param  list<int>  $rawPunchIds
     */
    public function matchManually(array $rawPunchIds, int $employeeId): void
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $rows = RawPunch::query()->whereIn('id', $rawPunchIds)->get(['id', 'punched_at']);

        DB::table('punch_matches')->insertOrIgnore($rows->map(fn (RawPunch $raw) => [
            'raw_punch_id' => $raw->id,
            'employee_id' => $employeeId,
            'punched_at' => $raw->punched_at->format('Y-m-d H:i:s'),
            'matched_by' => MatchedBy::Manual->value,
            'created_at' => $now,
        ])->all());
    }

    /**
     * @return array{0: int, 1: array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>}
     */
    private function matchNextBatch(): array
    {
        $cursor = (int) (DB::table('attendance_engine_state')->value('last_raw_punch_id') ?? 0);
        $raws = RawPunch::query()->where('id', '>', $cursor)->orderBy('id')->limit(self::BATCH)->get();

        if ($raws->isEmpty()) {
            return [0, []];
        }

        $owners = $this->owners($raws->pluck('employee_identifier'));
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $matches = [];
        $orphans = [];
        $affected = [];

        foreach ($raws as $raw) {
            $employeeId = $owners[mb_strtolower($raw->employee_identifier)] ?? null;

            if ($employeeId === null) {
                $orphans[] = [
                    'raw_punch_id' => $raw->id,
                    'employee_identifier' => $raw->employee_identifier,
                    'status' => OrphanStatus::Open->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                continue;
            }

            $matches[] = [
                'raw_punch_id' => $raw->id,
                'employee_id' => $employeeId,
                'punched_at' => $raw->punched_at->format('Y-m-d H:i:s'),
                'matched_by' => MatchedBy::Auto->value,
                'created_at' => $now,
            ];

            if (! $raw->too_old) {
                // The UTC date ±1 covers the punch's work date in any timezone.
                $date = LocalDate::parse($raw->punched_at->format(LocalDate::FORMAT));
                $range = $affected[$employeeId] ?? [$date->subDay(), $date->addDay()];
                $affected[$employeeId] = [$range[0]->min($date->subDay()), $range[1]->max($date->addDay())];
            }
        }

        foreach (array_chunk($matches, 200) as $chunk) {
            DB::table('punch_matches')->insertOrIgnore($chunk);
        }
        foreach (array_chunk($orphans, 200) as $chunk) {
            DB::table('orphan_punches')->insertOrIgnore($chunk);
        }

        $state = ['last_raw_punch_id' => (int) $raws->last()?->id, 'updated_at' => $now];
        if (DB::table('attendance_engine_state')->exists()) {
            DB::table('attendance_engine_state')->update($state);
        } else {
            DB::table('attendance_engine_state')->insert($state);
        }

        return [$raws->count(), $affected];
    }

    /**
     * Identifier (lowercase) => employee id. employee_code first, then extra
     * identifiers (they can never equal another employee's code). Soft-deleted
     * employees match nothing, so their punches become orphans.
     *
     * @param  Collection<int, string>  $identifiers
     * @return array<string, int>
     */
    private function owners(Collection $identifiers): array
    {
        $unique = $identifiers->unique()->values()->all();
        $owners = [];

        // Both columns are NOCASE, so IN matches case-insensitively.
        foreach (EmployeePunchIdentifier::query()->whereIn('identifier', $unique)->get(['identifier', 'employee_id']) as $row) {
            $owners[mb_strtolower($row->identifier)] = $row->employee_id;
        }
        foreach (Employee::query()->whereIn('employee_code', $unique)->get(['id', 'employee_code']) as $employee) {
            $owners[mb_strtolower($employee->employee_code)] = $employee->id;
        }

        return $owners;
    }

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $affected
     */
    private function recalculate(array $affected): void
    {
        if ($affected === []) {
            return;
        }

        $from = min(array_map(fn (array $range) => $range[0], $affected));
        $to = max(array_map(fn (array $range) => $range[1], $affected));

        if ($from->diffInDays($to) <= self::SHARED_RANGE_DAYS) {
            $this->calculator->recalculate(array_keys($affected), $from, $to);

            return;
        }

        // A backlog (device offline for days): only each employee's own range.
        foreach ($affected as $employeeId => [$start, $end]) {
            $this->calculator->recalculate([$employeeId], $start, $end);
        }
    }
}
