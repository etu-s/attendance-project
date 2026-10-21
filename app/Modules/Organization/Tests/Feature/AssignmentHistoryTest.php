<?php

declare(strict_types=1);

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\Location;
use App\Modules\Organization\Services\AssignmentService;
use App\Modules\Organization\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(LocalDate::parse('2026-06-15')->setTime(10, 0));
    $this->tenant = $this->createTenant('acme');
    tenancy()->initialize($this->tenant);

    $this->sales = Department::factory()->create(['name' => 'Sales']);
    $this->support = Department::factory()->create(['name' => 'Support']);
    $this->marketing = Department::factory()->create(['name' => 'Marketing']);
    $this->employee = Employee::factory()->inDepartment($this->sales)->create(['hire_date' => '2026-01-01']);
    $this->assignments = app(AssignmentService::class);
    $this->memberships = app(MembershipService::class);
});

function d(string $date): CarbonImmutable
{
    return LocalDate::parse($date);
}

/**
 * @return list<string> "dept from..to" rows, oldest first
 */
function timeline(Employee $employee): array
{
    return $employee->assignments()->with('department')->orderBy('effective_from')->get()
        ->map(fn ($a) => $a->department->name.' '.$a->effective_from->toDateString().'..'.($a->effective_to?->toDateString() ?? 'open'))
        ->all();
}

it('splits the covering period on transfer', function (): void {
    $this->assignments->transfer($this->employee, $this->support->id, null, d('2026-04-01'));

    expect(timeline($this->employee))->toBe([
        'Sales 2026-01-01..2026-03-31',
        'Support 2026-04-01..open',
    ]);
});

it('inserts a backdated transfer without touching later history', function (): void {
    $this->assignments->transfer($this->employee, $this->support->id, null, d('2026-04-01'));
    $this->assignments->transfer($this->employee, $this->marketing->id, null, d('2026-02-15'));

    expect(timeline($this->employee))->toBe([
        'Sales 2026-01-01..2026-02-14',
        'Marketing 2026-02-15..2026-03-31',
        'Support 2026-04-01..open',
    ]);
});

it('resolves the department on any date, including past days after a transfer', function (): void {
    $this->assignments->transfer($this->employee, $this->support->id, null, d('2026-04-01'));

    expect($this->assignments->on($this->employee, d('2026-03-31'))?->department->name)->toBe('Sales')
        ->and($this->assignments->on($this->employee, d('2026-04-01'))?->department->name)->toBe('Support')
        ->and($this->assignments->on($this->employee, d('2025-12-31')))->toBeNull();
});

it('treats a transfer on the period start day as a correction', function (): void {
    $this->assignments->transfer($this->employee, $this->support->id, null, d('2026-01-01'));

    expect(timeline($this->employee))->toBe(['Support 2026-01-01..open']);
});

it('rejects transfers before the hire date or to the same place', function (): void {
    expect(fn () => $this->assignments->transfer($this->employee, $this->support->id, null, d('2025-12-31')))
        ->toThrow(BusinessRuleException::class, 'before the employee\'s hire date');

    expect(fn () => $this->assignments->transfer($this->employee, $this->sales->id, null, d('2026-03-01')))
        ->toThrow(BusinessRuleException::class, 'already in this department');
});

it('treats a location change as a transfer', function (): void {
    $site = Location::factory()->create();

    $this->assignments->transfer($this->employee, $this->sales->id, $site->id, d('2026-05-01'));

    expect($this->assignments->on($this->employee, d('2026-05-01'))?->location_id)->toBe($site->id)
        ->and($this->assignments->on($this->employee, d('2026-04-30'))?->location_id)->toBeNull();
});

it('closes history on termination but refuses to cut through a later transfer', function (): void {
    $this->assignments->transfer($this->employee, $this->support->id, null, d('2026-04-01'));

    expect(fn () => $this->assignments->closeAt($this->employee, d('2026-03-15')))
        ->toThrow(BusinessRuleException::class, 'change after this date');

    $this->assignments->closeAt($this->employee, d('2026-05-31'));

    expect(timeline($this->employee))->toBe([
        'Sales 2026-01-01..2026-03-31',
        'Support 2026-04-01..2026-05-31',
    ]);
});

it('never lets periods overlap when a new period starts', function (): void {
    expect(fn () => $this->assignments->start($this->employee, $this->support->id, null, d('2026-03-01')))
        ->toThrow(BusinessRuleException::class);

    $this->assignments->closeAt($this->employee, d('2026-02-28'));
    $this->assignments->start($this->employee, $this->support->id, null, d('2026-05-01'));

    expect(timeline($this->employee))->toBe([
        'Sales 2026-01-01..2026-02-28',
        'Support 2026-05-01..open',
    ]);
});

it('orders an employee\'s groups by priority (lowest number wins)', function (): void {
    $night = Group::factory()->create(['name' => 'Night', 'priority' => 20]);
    $core = Group::factory()->create(['name' => 'Core', 'priority' => 5]);

    $this->memberships->add($this->employee, $night, d('2026-01-01'));
    $this->memberships->add($this->employee, $core, d('2026-03-01'));

    expect($this->memberships->on($this->employee, d('2026-02-01'))->map(fn ($m) => $m->group->name)->all())->toBe(['Night'])
        ->and($this->memberships->on($this->employee, d('2026-03-01'))->map(fn ($m) => $m->group->name)->all())->toBe(['Core', 'Night']);
});

it('ends memberships, removes ones that never took effect and refuses overlaps', function (): void {
    $night = Group::factory()->create();
    $future = Group::factory()->create();

    $this->memberships->add($this->employee, $night, d('2026-01-01'));
    expect(fn () => $this->memberships->add($this->employee, $night, d('2026-02-01')))
        ->toThrow(BusinessRuleException::class, 'already in this group');

    $this->memberships->end($this->employee, $night, d('2026-03-31'));
    expect($this->employee->memberships()->first()->effective_to->toDateString())->toBe('2026-03-31');

    $this->memberships->add($this->employee, $future, d('2026-09-01'));
    $this->memberships->end($this->employee, $future, d('2026-08-01'));
    expect($this->employee->memberships()->where('group_id', $future->id)->exists())->toBeFalse();
});

it('moves the start of history when the hire date is corrected', function (): void {
    $this->assignments->moveStart($this->employee, d('2025-12-01'));
    expect(timeline($this->employee))->toBe(['Sales 2025-12-01..open']);

    $this->assignments->transfer($this->employee, $this->support->id, null, d('2026-02-01'));
    expect(fn () => $this->assignments->moveStart($this->employee, d('2026-03-01')))
        ->toThrow(BusinessRuleException::class);
});
