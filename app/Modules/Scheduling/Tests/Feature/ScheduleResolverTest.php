<?php

declare(strict_types=1);

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\Location;
use App\Modules\Organization\Services\AssignmentService;
use App\Modules\Organization\Services\EmployeeService;
use App\Modules\Organization\Services\MembershipService;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Scheduling\Models\TemporarySchedule;
use App\Modules\Scheduling\Services\ScheduleResolver;
use App\Modules\Scheduling\Support\ResolvedDay;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Dates used below (2026): Mon 02 Mar … Sun 08 Mar; Mon 09 Mar … Sun 15 Mar.
 * Weekly shifts work Mon–Fri. Workspace timezone UTC unless stated.
 */
beforeEach(function (): void {
    $this->travelTo(LocalDate::parse('2026-03-01')->setTime(9, 0));
    tenancy()->initialize($this->createTenant('acme'));

    $weekly = fn (string $from, string $to, string $name) => Shift::factory()
        ->on(Timetable::factory()->between($from, $to)->create(['name' => $name]), [0, 1, 2, 3, 4])
        ->create(['name' => $name.' week']);

    $this->orgShift = $weekly('09:00', '17:00', 'Office');
    $this->deptShift = $weekly('07:00', '15:00', 'Early');
    $this->groupShift = $weekly('22:00', '06:00', 'Night');
    $this->empShift = $weekly('10:00', '18:00', 'Late');
    $this->tempShift = $weekly('12:00', '20:00', 'Cover');

    $this->ops = Department::factory()->create(['name' => 'Operations']);
    $this->warehouse = Department::factory()->childOf($this->ops)->create(['name' => 'Warehouse']);
    $this->finance = Department::factory()->create(['name' => 'Finance']);
    $this->employee = Employee::factory()->inDepartment($this->warehouse)->create(['hire_date' => '2026-01-01']);

    ScheduleAssignment::factory()->state(['shift_id' => $this->orgShift->id])->create();
});

/**
 * @return array<string, string> date => "status source[name] HH:MM-HH:MM"
 */
function resolveDays(Employee $employee, string $from, string $to): array
{
    $days = app(ScheduleResolver::class)->forEmployee($employee->fresh(), LocalDate::parse($from), LocalDate::parse($to));

    return array_map(function (ResolvedDay $d): string {
        $source = $d->source === null ? '-' : $d->source.'['.($d->sourceName ?? '').']';
        $times = implode(',', array_map(fn ($p) => $p->startsAt->format('H:i').'-'.$p->endsAt->format('H:i'), $d->periods));

        return trim("{$d->status->value} {$source} {$times}");
    }, $days);
}

describe('precedence', function (): void {
    it('falls back to the organization default', function (): void {
        expect(resolveDays($this->employee, '2026-03-06', '2026-03-07'))->toBe([
            '2026-03-06' => 'working organization[Organization default] 09:00-17:00',
            '2026-03-07' => 'rest organization[Organization default]',
        ]);
    });

    it('uses the department schedule, inherited from the nearest ancestor', function (): void {
        ScheduleAssignment::factory()->forDepartment($this->ops)->state(['shift_id' => $this->deptShift->id])->create();

        $day = app(ScheduleResolver::class)->forEmployee($this->employee, LocalDate::parse('2026-03-02'), LocalDate::parse('2026-03-02'))['2026-03-02'];

        expect($day->source)->toBe('department')
            ->and($day->sourceName)->toBe('Operations')
            ->and($day->inheritedFrom)->toBe('Operations')
            ->and(resolveDays($this->employee, '2026-03-02', '2026-03-02')['2026-03-02'])->toBe('working department[Operations] 07:00-15:00');

        // The employee's own department wins over its parent.
        ScheduleAssignment::factory()->forDepartment($this->warehouse)->state(['shift_id' => $this->empShift->id])->create();
        expect(resolveDays($this->employee, '2026-03-02', '2026-03-02')['2026-03-02'])->toBe('working department[Warehouse] 10:00-18:00');
    });

    it('uses the lowest-priority group that has a schedule', function (): void {
        ScheduleAssignment::factory()->forDepartment($this->warehouse)->state(['shift_id' => $this->deptShift->id])->create();
        $top = Group::factory()->create(['name' => 'Top', 'priority' => 1]);          // no schedule: skipped
        $night = Group::factory()->create(['name' => 'Night crew', 'priority' => 5]);
        $other = Group::factory()->create(['name' => 'Other', 'priority' => 9]);
        foreach ([$top, $night, $other] as $group) {
            app(MembershipService::class)->add($this->employee, $group, LocalDate::parse('2026-01-01'));
        }
        ScheduleAssignment::factory()->forGroup($other)->state(['shift_id' => $this->empShift->id])->create();
        ScheduleAssignment::factory()->forGroup($night)->state(['shift_id' => $this->groupShift->id])->create();

        expect(resolveDays($this->employee, '2026-03-02', '2026-03-02')['2026-03-02'])->toBe('working group[Night crew] 22:00-06:00');
    });

    it('lets an employee schedule beat groups, and a temporary schedule beat everything', function (): void {
        $group = Group::factory()->create(['priority' => 1]);
        app(MembershipService::class)->add($this->employee, $group, LocalDate::parse('2026-01-01'));
        ScheduleAssignment::factory()->forGroup($group)->state(['shift_id' => $this->groupShift->id])->create();
        ScheduleAssignment::factory()->forEmployee($this->employee)->state(['shift_id' => $this->empShift->id])->create();
        TemporarySchedule::factory()->create([
            'employee_id' => $this->employee->id, 'date_from' => '2026-03-03', 'date_to' => '2026-03-04',
            'shift_id' => $this->tempShift->id, 'anchor_date' => '2026-03-02', 'reason' => 'Covering',
        ]);

        expect(resolveDays($this->employee, '2026-03-02', '2026-03-05'))->toBe([
            '2026-03-02' => 'working employee['.$this->employee->fullName().'] 10:00-18:00',
            '2026-03-03' => 'working temporary[Covering] 12:00-20:00',
            '2026-03-04' => 'working temporary[Covering] 12:00-20:00',
            '2026-03-05' => 'working employee['.$this->employee->fullName().'] 10:00-18:00',
        ]);
    });

    it('gives a temporary day off', function (): void {
        TemporarySchedule::factory()->create(['employee_id' => $this->employee->id, 'date_from' => '2026-03-02', 'date_to' => '2026-03-02', 'reason' => 'Swap']);

        expect(resolveDays($this->employee, '2026-03-02', '2026-03-03'))->toBe([
            '2026-03-02' => 'day_off temporary[Swap]',
            '2026-03-03' => 'working organization[Organization default] 09:00-17:00',
        ]);
    });

    it('reports unscheduled when no level has a schedule', function (): void {
        ScheduleAssignment::query()->delete();

        expect(resolveDays($this->employee, '2026-03-02', '2026-03-02')['2026-03-02'])->toBe('unscheduled -');
    });
});

describe('changes over time', function (): void {
    it('switches schedule mid-period', function (): void {
        ScheduleAssignment::factory()->forDepartment($this->warehouse)->between('2026-01-01', '2026-03-03')->state(['shift_id' => $this->deptShift->id])->create();
        ScheduleAssignment::factory()->forDepartment($this->warehouse)->between('2026-03-04')->state(['shift_id' => $this->empShift->id])->create();

        expect(resolveDays($this->employee, '2026-03-03', '2026-03-04'))->toBe([
            '2026-03-03' => 'working department[Warehouse] 07:00-15:00',
            '2026-03-04' => 'working department[Warehouse] 10:00-18:00',
        ]);
    });

    it('follows a transfer and its location timezone date by date', function (): void {
        ScheduleAssignment::factory()->forDepartment($this->finance)->state(['shift_id' => $this->deptShift->id])->create();
        $karachi = Location::factory()->create(['timezone' => 'Asia/Karachi']);
        app(AssignmentService::class)->transfer($this->employee, $this->finance->id, $karachi->id, LocalDate::parse('2026-03-04'));

        expect(resolveDays($this->employee, '2026-03-03', '2026-03-04'))->toBe([
            '2026-03-03' => 'working organization[Organization default] 09:00-17:00',
            '2026-03-04' => 'working department[Finance] 02:00-10:00', // 07:00 in Karachi (UTC+5)
        ]);
    });

    it('respects the cycle anchor of a rotation', function (): void {
        $rota = Shift::factory()->rotating(2)->on(Timetable::factory()->between('08:00', '16:00')->create(), [0])->create();
        ScheduleAssignment::factory()->forEmployee($this->employee)->state([
            'shift_id' => $rota->id, 'effective_from' => '2026-03-02', 'anchor_date' => '2026-03-03',
        ])->create();

        expect(array_map(fn ($d) => explode(' ', $d)[0], resolveDays($this->employee, '2026-03-02', '2026-03-05')))->toBe([
            '2026-03-02' => 'rest', '2026-03-03' => 'working', '2026-03-04' => 'rest', '2026-03-05' => 'working',
        ]);
    });

    it('has no schedule before hiring or after termination, but keeps the last night', function (): void {
        $night = Shift::factory()->rotating(1)->on(Timetable::factory()->between('22:00', '06:00')->create(), [0])->create();
        $employee = Employee::factory()->inDepartment($this->finance)->create(['hire_date' => '2026-03-03']);
        ScheduleAssignment::factory()->forEmployee($employee)->state(['shift_id' => $night->id])->create();
        app(EmployeeService::class)
            ->terminate($employee->fresh(), LocalDate::parse('2026-03-05'), false, $this->createTenantUser(tenant()));

        expect(resolveDays($employee, '2026-03-02', '2026-03-06'))->toBe([
            '2026-03-02' => 'not_employed -',
            '2026-03-03' => 'working employee['.$employee->fullName().'] 22:00-06:00',
            '2026-03-04' => 'working employee['.$employee->fullName().'] 22:00-06:00',
            '2026-03-05' => 'working employee['.$employee->fullName().'] 22:00-06:00',
            '2026-03-06' => 'not_employed -',
        ]);
    });
});

describe('holidays', function (): void {
    it('turns a working day into a holiday, but not a rest day or a temporary schedule', function (): void {
        Holiday::factory()->create(['date' => '2026-03-02', 'name' => 'Spring day']);
        Holiday::factory()->create(['date' => '2026-03-07', 'name' => 'Saturday holiday']);
        Holiday::factory()->create(['date' => '2026-03-03', 'name' => 'Covered']);
        TemporarySchedule::factory()->create([
            'employee_id' => $this->employee->id, 'date_from' => '2026-03-03', 'date_to' => '2026-03-03',
            'shift_id' => $this->tempShift->id, 'anchor_date' => '2026-03-02',
        ]);

        $days = app(ScheduleResolver::class)->forEmployee($this->employee, LocalDate::parse('2026-03-02'), LocalDate::parse('2026-03-07'));

        expect($days['2026-03-02']->status->value)->toBe('holiday')
            ->and($days['2026-03-02']->holiday)->toBe('Spring day')
            ->and($days['2026-03-02']->periods)->toHaveCount(1) // kept for overtime rules
            ->and($days['2026-03-03']->status->value)->toBe('working')
            ->and($days['2026-03-07']->status->value)->toBe('rest');
    });

    it('applies location holidays only at that location, and recurring ones every later year', function (): void {
        $karachi = Location::factory()->create();
        Holiday::factory()->create(['date' => '2026-03-04', 'location_id' => $karachi->id]);
        Holiday::factory()->create(['date' => '2025-03-05', 'recurs_yearly' => true]);

        $statuses = fn () => array_map(fn ($d) => explode(' ', $d)[0], resolveDays($this->employee, '2026-03-04', '2026-03-05'));

        expect($statuses())->toBe(['2026-03-04' => 'working', '2026-03-05' => 'holiday']);

        app(AssignmentService::class)->transfer($this->employee, $this->warehouse->id, $karachi->id, LocalDate::parse('2026-03-02'));
        expect($statuses())->toBe(['2026-03-04' => 'holiday', '2026-03-05' => 'holiday']);
    });
});

it('does not grow its query count with employees or days', function (): void {
    $group = Group::factory()->create();
    ScheduleAssignment::factory()->forGroup($group)->state(['shift_id' => $this->groupShift->id])->create();
    ScheduleAssignment::factory()->forDepartment($this->warehouse)->state(['shift_id' => $this->deptShift->id])->create();
    Holiday::factory()->create(['date' => '2026-03-04']);

    $make = function (int $n) use ($group) {
        Employee::factory()->count($n)->inDepartment($this->warehouse)->create(['hire_date' => '2026-01-01'])
            ->each(fn (Employee $e) => app(MembershipService::class)->add($e, $group, LocalDate::parse('2026-01-01')));
    };
    $count = function (int $days): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        app(ScheduleResolver::class)->forEmployees(Employee::query()->get(), LocalDate::parse('2026-03-01'), LocalDate::parse('2026-03-01')->addDays($days - 1));
        DB::getEventDispatcher()?->forget(QueryExecuted::class);

        return $queries;
    };

    // Each measurement follows a warm-up run, so one-off cache fills (settings) are not counted.
    $make(2);
    $count(7);
    $few = $count(7);
    $make(20);
    $count(7);

    expect($count(7))->toBe($few)->and($count(31))->toBe($few);
});
