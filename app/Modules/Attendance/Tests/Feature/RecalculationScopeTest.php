<?php

declare(strict_types=1);

use App\Modules\Attendance\Events\AttendanceDaysChanged;
use App\Modules\Attendance\Events\AttendanceRecalculated;
use App\Modules\Attendance\Jobs\RecalculateAttendance;
use App\Modules\Attendance\Services\AttendanceCalculator;
use App\Modules\Attendance\Tests\Support\AttendanceTesting;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\Location;
use App\Modules\Scheduling\Events\ScheduleChanged;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class, AttendanceTesting::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-06 12:00:00', 'UTC'));
    $this->attendanceWorkspace();
});

it('fires one AttendanceDaysChanged per run with every changed date', function (): void {
    $this->tenant->run(function (): void {
        Employee::factory()->count(2)->inDepartment($this->department)->create(['hire_date' => '2026-01-01']);

        $runs = [];
        $perEmployee = 0;
        Event::listen(AttendanceDaysChanged::class, function (AttendanceDaysChanged $e) use (&$runs): void {
            $runs[] = $e->dates;
        });
        Event::listen(AttendanceRecalculated::class, function () use (&$perEmployee): void {
            $perEmployee++;
        });

        app(AttendanceCalculator::class)->recalculate(null, CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-04'));
        expect($runs)->toBe([['2026-03-02', '2026-03-03', '2026-03-04']])->and($perEmployee)->toBe(3);

        // Nothing changed: no event.
        app(AttendanceCalculator::class)->recalculate(null, CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-04'));
        expect($runs)->toHaveCount(1);
    });
});

it('recalculates only the people a schedule change can reach', function (): void {
    Queue::fake();

    $this->tenant->run(function (): void {
        $child = Department::factory()->childOf($this->department)->create();
        $other = Department::factory()->create();
        $location = Location::factory()->create();
        $inChild = Employee::factory()->inDepartment($child)->create(['hire_date' => '2026-01-01']);
        $elsewhere = Employee::factory()->inDepartment($other, $location)->create(['hire_date' => '2026-01-01']);
        // Moved out of the department before the range: not reached.
        $gone = Employee::factory()->inDepartment($this->department)->create(['hire_date' => '2025-01-01', 'termination_date' => '2025-12-31']);
        $group = Group::factory()->create();
        $elsewhere->memberships()->create(['group_id' => $group->id, 'effective_from' => '2026-02-01']);

        $dispatched = function (string $type, ?int $id): ?array {
            Queue::fake();
            event(new ScheduleChanged($type, $id, '2026-03-02', null));
            $jobs = Queue::pushed(RecalculateAttendance::class);

            return $jobs->isEmpty() ? [] : $jobs->first()->employeeIds;
        };

        expect($dispatched('department', $this->department->id))->toBe([$this->employee->id, $inChild->id])
            ->and($dispatched('group', $group->id))->toBe([$elsewhere->id])
            ->and($dispatched('location', $location->id))->toBe([$elsewhere->id])
            ->and($dispatched('employee', $inChild->id))->toBe([$inChild->id])
            ->and($dispatched('organization', null))->toBeNull()
            ->and($dispatched('group', Group::factory()->create()->id))->toBe([])
            ->and($gone->id)->toBeInt();
    });
});
