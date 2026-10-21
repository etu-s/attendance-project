<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Tests\Support;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\PunchSource;
use App\Modules\Attendance\Services\AttendanceCalculator;
use App\Modules\Attendance\Services\PunchIngestor;
use App\Modules\Attendance\Services\PunchSourceService;
use App\Modules\Central\Models\Tenant;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Models\TimetableBreak;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/**
 * A ready workspace for attendance tests: an "Office" timetable 09:00–17:00
 * with an unpaid 60-minute lunch at 13:00, Monday–Friday, as the organization
 * default from 2026-01-01; one department; employee E-1 hired 2026-01-01.
 * Punches go through the real ingestion pipeline (sync queue in tests).
 *
 * @property Tenant $tenant
 * @property Timetable $timetable
 * @property Shift $shift
 * @property Department $department
 * @property Employee $employee
 */
trait AttendanceTesting
{
    protected ?PunchSource $testSource = null;

    protected function attendanceWorkspace(string $timezone = 'UTC', string $subdomain = 'acme'): void
    {
        $this->tenant = $this->createTenant($subdomain, ['timezone' => $timezone]);
        $this->testSource = null;

        [$this->timetable, $this->shift, $this->department, $this->employee] = $this->tenant->run(function (): array {
            $timetable = $this->officeTimetable();
            $shift = Shift::factory()->on($timetable, [0, 1, 2, 3, 4])->create(['name' => 'Office week']);
            ScheduleAssignment::factory()->create(['shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'anchor_date' => '2026-01-05']);
            $department = Department::factory()->create();
            $employee = Employee::factory()->inDepartment($department)->create(['employee_code' => 'E-1', 'hire_date' => '2026-01-01']);

            return [$timetable, $shift, $department, $employee];
        });
    }

    /**
     * Tenant context only.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function officeTimetable(array $attributes = []): Timetable
    {
        $timetable = Timetable::factory()->between('09:00', '17:00')->create(['name' => 'Office'] + $attributes);
        TimetableBreak::query()->create([
            'timetable_id' => $timetable->id, 'kind' => 'fixed', 'start_offset' => 240,
            'duration_minutes' => 60, 'is_paid' => false, 'position' => 0,
        ]);

        return $timetable;
    }

    /**
     * Ingests punches (UTC "Y-m-d H:i[:s]", optionally "… in"/"… out" for a
     * direction) for an employee code, then lets the sync queue process them.
     */
    protected function punch(string $code, string ...$times): void
    {
        $this->tenant->run(function () use ($code, $times): void {
            $this->testSource ??= app(PunchSourceService::class)->create(['name' => 'Test terminal', 'kind' => 'device'])[0];

            app(PunchIngestor::class)->ingest($this->testSource, array_map(function (string $time) use ($code): array {
                $parts = explode(' ', $time);
                $direction = in_array(end($parts), ['in', 'out'], true) ? array_pop($parts) : null;

                return [
                    'employee_identifier' => $code,
                    'punched_at' => CarbonImmutable::parse(implode(' ', $parts), 'UTC')->format('Y-m-d\TH:i:s\Z'),
                    'direction' => $direction,
                ];
            }, $times));
        });
    }

    /**
     * POSTs to an ingestion endpoint with a punch source key (default: $this->key).
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function postPunch(array $body, ?string $key = null, string $path = 'punches', array $headers = [], ?Tenant $tenant = null): TestResponse
    {
        return $this->postJson($this->tenantUrl($tenant ?? $this->tenant, $path), $body, ['Authorization' => 'Bearer '.($key ?? $this->key)] + $headers);
    }

    protected function day(string $date, ?Employee $employee = null): AttendanceDay
    {
        $employee ??= $this->employee;

        return $this->tenant->run(fn () => AttendanceDay::query()
            ->with(['periods', 'punches'])
            ->where('employee_id', $employee->id)
            ->where('work_date', $date)
            ->firstOrFail());
    }

    protected function recalculate(string $from, string $to, ?Employee $employee = null): int
    {
        return $this->tenant->run(fn () => app(AttendanceCalculator::class)->recalculate(
            [($employee ?? $this->employee)->id],
            LocalDate::parse($from),
            LocalDate::parse($to),
        ));
    }
}
