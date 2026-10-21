<?php

declare(strict_types=1);

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Services\ShiftCalendar;
use App\Modules\Timetable\Support\ScheduledPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    tenancy()->initialize($this->createTenant('acme'));
    $this->calendar = app(ShiftCalendar::class);
});

/**
 * @return array<string, list<string>> date => ["HH:MMZ-HH:MMZ (minutes)"] in UTC
 */
function expandShift(Shift $shift, string $anchor, string $from, string $to, string $timezone = 'Europe/Berlin'): array
{
    $days = app(ShiftCalendar::class)->expand($shift->fresh(), LocalDate::parse($anchor), LocalDate::parse($from), LocalDate::parse($to), $timezone);

    return array_map(fn (array $periods) => array_map(
        fn (ScheduledPeriod $p) => $p->startsAt->format('m-d H:i').'Z-'.$p->endsAt->format('m-d H:i').'Z ('.$p->minutes().')',
        $periods,
    ), $days);
}

it('lays a Monday-to-Friday week onto dates in the given timezone', function (): void {
    $day = Timetable::factory()->between('09:00', '18:00')->create();
    $shift = Shift::factory()->on($day, [0, 1, 2, 3, 4])->create();

    $days = expandShift($shift, '2026-03-04', '2026-03-02', '2026-03-08');

    expect(array_keys($days))->toBe(['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06', '2026-03-07', '2026-03-08'])
        ->and($days['2026-03-02'])->toBe(['03-02 08:00Z-03-02 17:00Z (540)'])
        ->and($days['2026-03-06'])->toHaveCount(1)
        ->and($days['2026-03-07'])->toBe([])
        ->and($days['2026-03-08'])->toBe([]);
});

it('assigns an overnight period to the day it starts', function (): void {
    $night = Timetable::factory()->between('22:00', '06:00')->create();
    $shift = Shift::factory()->on($night, [0])->create();

    $days = expandShift($shift, '2026-02-02', '2026-02-02', '2026-02-03');

    expect($days['2026-02-02'])->toBe(['02-02 21:00Z-02-03 05:00Z (480)'])
        ->and($days['2026-02-03'])->toBe([]);
});

it('gives a night shift its real length across DST changes', function (): void {
    $night = Timetable::factory()->between('22:00', '06:00')->create();
    $shift = Shift::factory()->rotating(1)->on($night, [0])->create();

    // Europe/Berlin: clocks go forward 2026-03-29 02:00, back 2026-10-25 03:00.
    $spring = expandShift($shift, '2026-03-27', '2026-03-28', '2026-03-29');
    $autumn = expandShift($shift, '2026-10-23', '2026-10-24', '2026-10-25');

    expect($spring['2026-03-28'])->toBe(['03-28 21:00Z-03-29 04:00Z (420)'])
        ->and($spring['2026-03-29'])->toBe(['03-29 20:00Z-03-30 04:00Z (480)'])
        ->and($autumn['2026-10-24'])->toBe(['10-24 20:00Z-10-25 05:00Z (540)'])
        ->and($autumn['2026-10-25'])->toBe(['10-25 21:00Z-10-26 05:00Z (480)']);
});

it('moves a start inside the skipped DST hour forward by the gap', function (): void {
    $early = Timetable::factory()->between('02:30', '10:30')->create();
    $shift = Shift::factory()->rotating(1)->on($early, [0])->create();

    // 02:30 does not exist in Berlin on 2026-03-29; it becomes 03:30 CEST (01:30Z).
    expect(expandShift($shift, '2026-03-29', '2026-03-29', '2026-03-29')['2026-03-29'])
        ->toBe(['03-29 01:30Z-03-29 08:30Z (420)']);
});

it('rotates a 4-on/4-off cycle across a month end and before the anchor', function (): void {
    $day = Timetable::factory()->between('07:00', '19:00')->create();
    $shift = Shift::factory()->rotating(8)->on($day, [0, 1, 2, 3])->create();

    $working = array_keys(array_filter(expandShift($shift, '2026-01-28', '2026-01-23', '2026-02-12', 'UTC')));

    expect($working)->toBe([
        '2026-01-23',                                           // index 3 of the previous cycle
        '2026-01-28', '2026-01-29', '2026-01-30', '2026-01-31',
        '2026-02-05', '2026-02-06', '2026-02-07', '2026-02-08',
    ]);
});

it('counts week cycles from the Monday of the anchor week', function (): void {
    $day = Timetable::factory()->create();
    $shift = Shift::factory()->state(['cycle_length' => 2])->on($day, [7])->create(); // Monday of week 2

    // Anchor on a Wednesday: week 1 is the week of 2026-03-02.
    $working = array_keys(array_filter(expandShift($shift, '2026-03-04', '2026-03-01', '2026-03-31', 'UTC')));

    expect($working)->toBe(['2026-03-09', '2026-03-23'])
        ->and($this->calendar->cycleIndex($shift, LocalDate::parse('2026-03-04'), LocalDate::parse('2026-03-02')))->toBe(0)
        ->and($this->calendar->cycleIndex($shift, LocalDate::parse('2026-03-04'), LocalDate::parse('2026-03-15')))->toBe(13);
});

it('orders split-shift periods within a day', function (): void {
    $evening = Timetable::factory()->between('16:00', '20:00')->create();
    $morning = Timetable::factory()->between('08:00', '12:00')->create();
    $shift = Shift::factory()->rotating(1)->on($evening, [0])->on($morning, [0])->create();

    expect(expandShift($shift, '2026-05-04', '2026-05-04', '2026-05-04', 'UTC')['2026-05-04'])
        ->toBe(['05-04 08:00Z-05-04 12:00Z (240)', '05-04 16:00Z-05-04 20:00Z (240)']);
});

it('refuses inverted or overly long ranges', function (): void {
    $shift = Shift::factory()->create();

    expect(fn () => expandShift($shift, '2026-01-01', '2026-02-01', '2026-01-01'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => expandShift($shift, '2026-01-01', '2026-01-01', '2027-06-01'))->toThrow(InvalidArgumentException::class);
});
