<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Contracts\PeriodLock;
use App\Modules\Scheduling\Events\ScheduleChanged;
use App\Modules\Scheduling\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class HolidayService
{
    public function __construct(
        private readonly PeriodLock $locks,
    ) {}

    /**
     * Holidays that fall in $year (one-off ones dated that year, recurring
     * ones from earlier years), each with `occurs_on` set, ordered by date.
     *
     * @return Collection<int, Holiday>
     */
    public function forYear(int $year, ?int $locationId = null): Collection
    {
        $from = LocalDate::parse(sprintf('%04d-01-01', $year));
        $to = LocalDate::parse(sprintf('%04d-12-31', $year));

        return $this->candidates($from, $to)
            ->with('location')
            ->when($locationId !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('location_id')->orWhere('location_id', $locationId)))
            ->get()
            ->map(function (Holiday $holiday) use ($year): ?Holiday {
                $on = $holiday->occurrenceIn($year);
                $holiday->setAttribute('occurs_on', $on?->format(LocalDate::FORMAT));

                return $on === null ? null : $holiday;
            })
            ->filter()
            ->sortBy(fn (Holiday $h) => $h->getAttribute('occurs_on').' '.$h->name)
            ->values();
    }

    /**
     * Every holiday occurrence in a date range: date => holidays on that date.
     *
     * @return array<string, list<Holiday>>
     */
    public function occurrences(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = [];

        foreach ($this->candidates($from, $to)->get() as $holiday) {
            for ($year = $from->year; $year <= $to->year; $year++) {
                $on = $holiday->occurrenceIn($year);
                if ($on !== null && $on->betweenIncluded($from, $to)) {
                    $result[$on->format(LocalDate::FORMAT)][] = $holiday;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array{date: string, name: string, location_id?: int|null, recurs_yearly?: bool}  $data
     */
    public function create(array $data): Holiday
    {
        $this->assertUnique($data['date'], $data['location_id'] ?? null, null);
        $this->assertOpen(LocalDate::parse($data['date']), (bool) ($data['recurs_yearly'] ?? false));
        $holiday = Holiday::query()->create($data);
        $this->changed($holiday);

        return $holiday->load('location');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Holiday $holiday, array $data): Holiday
    {
        $before = clone $holiday;
        $this->assertUnique(
            (string) ($data['date'] ?? $holiday->date->toDateString()),
            array_key_exists('location_id', $data) ? $data['location_id'] : $holiday->location_id,
            $holiday->id,
        );
        $this->assertOpen($holiday->date, $holiday->recurs_yearly);
        $this->assertOpen(
            isset($data['date']) ? LocalDate::parse((string) $data['date']) : $holiday->date,
            (bool) ($data['recurs_yearly'] ?? $holiday->recurs_yearly),
        );
        $holiday->update($data);
        $this->changed($before);
        $this->changed($holiday);

        return $holiday->load('location');
    }

    public function delete(Holiday $holiday): void
    {
        $this->assertOpen($holiday->date, $holiday->recurs_yearly);
        $holiday->delete();
        $this->changed($holiday);
    }

    /**
     * @return Builder<Holiday>
     */
    private function candidates(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return Holiday::query()->where(fn (Builder $q) => $q
            ->whereBetween('date', [$from->format(LocalDate::FORMAT), $to->format(LocalDate::FORMAT)])
            ->orWhere(fn (Builder $r) => $r->where('recurs_yearly', true)->where('date', '<=', $to->format(LocalDate::FORMAT))));
    }

    private function assertUnique(string $date, mixed $locationId, ?int $ignoreId): void
    {
        // Unique index cannot cover this: SQLite treats NULL location_ids as distinct.
        $exists = Holiday::query()
            ->where('date', LocalDate::parse($date)->format(LocalDate::FORMAT))
            ->when($locationId === null, fn (Builder $q) => $q->whereNull('location_id'), fn (Builder $q) => $q->where('location_id', $locationId))
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['date' => 'There is already a holiday on this date for the same scope.']);
        }
    }

    /**
     * A one-off holiday changes its own date; a yearly one its day in every
     * year from its date on. None of those days may be in a locked period.
     */
    private function assertOpen(CarbonImmutable $date, bool $recurs): void
    {
        if (! $recurs) {
            $this->locks->assertOpen($date, $date, 'This holiday change');

            return;
        }

        foreach ($this->locks->overlapping($date, $date->addYears(100)) as $period) {
            $start = LocalDate::parse($period['start_date']);
            $end = LocalDate::parse($period['end_date']);
            for ($year = $start->year; $year <= $end->year; $year++) {
                // Skips 29 February in years without one.
                if (! checkdate($date->month, $date->day, $year)) {
                    continue;
                }
                $occurrence = $date->setDate($year, $date->month, $date->day);
                if ($occurrence->greaterThanOrEqualTo($date) && $occurrence->betweenIncluded($start, $end)) {
                    $this->locks->assertOpen($occurrence, $occurrence, 'This holiday change');
                }
            }
        }
    }

    private function changed(Holiday $holiday): void
    {
        event(new ScheduleChanged(
            $holiday->location_id === null ? 'organization' : 'location',
            $holiday->location_id,
            $holiday->date->format(LocalDate::FORMAT),
            $holiday->recurs_yearly ? null : $holiday->date->format(LocalDate::FORMAT),
        ));
    }
}
