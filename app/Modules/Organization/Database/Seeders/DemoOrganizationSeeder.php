<?php

declare(strict_types=1);

namespace App\Modules\Organization\Database\Seeders;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\Location;
use App\Modules\Organization\Services\AssignmentService;
use App\Modules\Organization\Services\EmployeeService;
use Illuminate\Database\Seeder;

/**
 * Local demo structure for a tenant (run inside tenant context). Skips
 * tenants that already have employees.
 */
final class DemoOrganizationSeeder extends Seeder
{
    public function run(EmployeeService $employees, AssignmentService $assignments): void
    {
        if (Employee::query()->exists()) {
            return;
        }

        $ops = Department::query()->firstOrCreate(['code' => 'OPS'], ['name' => 'Operations']);
        $warehouse = Department::query()->firstOrCreate(['code' => 'WH'], ['name' => 'Warehouse', 'parent_id' => $ops->id]);
        $hr = Department::query()->firstOrCreate(['code' => 'HR'], ['name' => 'Human Resources']);
        $finance = Department::query()->firstOrCreate(['code' => 'FIN'], ['name' => 'Finance']);

        $lahore = Location::query()->firstOrCreate(['code' => 'LHE'], ['name' => 'Lahore HQ', 'timezone' => 'Asia/Karachi']);
        $dubai = Location::query()->firstOrCreate(['code' => 'DXB'], ['name' => 'Dubai Office', 'timezone' => 'Asia/Dubai']);

        $night = Group::query()->firstOrCreate(['code' => 'NIGHT'], ['name' => 'Night shift', 'priority' => 10]);
        $drivers = Group::query()->firstOrCreate(['code' => 'DRIVERS'], ['name' => 'Drivers', 'priority' => 20]);

        $people = [
            ['A-001', 'Ayesha', 'Khan', $ops, $lahore, [], '2024-03-01'],
            ['A-002', 'Bilal', 'Ahmed', $warehouse, $lahore, [$night], '2024-06-15'],
            ['A-003', 'Sara', 'Ali', $warehouse, $lahore, [$night, $drivers], '2025-01-10'],
            ['A-004', 'Omar', 'Raza', $warehouse, $lahore, [$drivers], '2025-02-01'],
            ['A-005', 'Hina', 'Shah', $hr, $lahore, [], '2023-09-01'],
            ['A-006', 'Zain', 'Malik', $finance, $dubai, [], '2024-11-20'],
            ['A-007', 'Fatima', 'Noor', $finance, $dubai, [], '2025-05-05'],
            ['A-008', 'Usman', 'Tariq', $ops, $dubai, [$night], '2025-08-01'],
            ['A-009', 'Mariam', 'Iqbal', $hr, $lahore, [], '2026-01-12'],
            ['A-010', 'Hamza', 'Butt', $warehouse, $lahore, [$night], '2026-02-02'],
            ['A-011', 'Areeba', 'Javed', $ops, $lahore, [], '2024-04-04'],
            ['A-012', 'Danish', 'Qureshi', $finance, $dubai, [], '2023-12-01'],
        ];

        foreach ($people as [$code, $first, $last, $department, $location, $groups, $hired]) {
            $employees->create([
                'employee_code' => $code,
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower($first.'.'.$last).'@acme.test',
                'employment_type' => 'full_time',
                'hire_date' => $hired,
                'department_id' => $department->id,
                'location_id' => $location->id,
                'group_ids' => array_map(fn (Group $g) => $g->id, $groups),
            ], null);
        }

        // Some history to look at: one transfer and one leaver.
        $areeba = Employee::query()->where('employee_code', 'A-011')->firstOrFail();
        $employees->transfer($areeba, $finance->id, $dubai->id, LocalDate::parse('2025-07-01'));

        $danish = Employee::query()->where('employee_code', 'A-012')->firstOrFail();
        $assignments->closeAt($danish, LocalDate::parse('2025-10-31'));
        $danish->update(['status' => 'terminated', 'termination_date' => '2025-10-31']);
    }
}
