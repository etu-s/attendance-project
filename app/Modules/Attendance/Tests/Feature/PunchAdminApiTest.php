<?php

declare(strict_types=1);

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\EmployeePunchIdentifier;
use App\Modules\Attendance\Models\OrphanPunch;
use App\Modules\Attendance\Models\PunchMatch;
use App\Modules\Attendance\Services\PunchSourceService;
use App\Modules\Attendance\Tests\Support\AttendanceTesting;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class, AttendanceTesting::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->attendanceWorkspace();
    $this->admin = $this->tenantAdmin($this->tenant);
    $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
    [$this->source, $this->key] = $this->tenant->run(fn () => app(PunchSourceService::class)->create(['name' => 'Front door', 'kind' => 'device']));
});

describe('punch sources', function (): void {
    it('creates a source and shows its key and signing secret only once', function (): void {
        $response = $this->actingAsTenantUser($this->admin)
            ->postJson($this->tenantUrl($this->tenant, 'punch-sources'), ['name' => 'HR suite', 'kind' => 'integration', 'signed' => true, 'ip_allowlist' => ['10.0.0.0/24', '192.168.1.5']])
            ->assertCreated()
            ->assertJsonPath('data.signed', true)
            ->assertJsonPath('data.ip_allowlist', ['10.0.0.0/24', '192.168.1.5']);

        expect($response->json('key'))->toMatch('/^ams_[a-z0-9]{10}_[A-Za-z0-9]{40}$/')
            ->and($response->json('signing_secret'))->toHaveLength(48);

        $index = $this->getJson($this->tenantUrl($this->tenant, 'punch-sources'))->assertOk()->assertJsonCount(2, 'data');
        expect($index->content())->not->toContain($response->json('key'))->not->toContain('key_hash');
    });

    it('is managed by the Tenant Admin only', function (): void {
        $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, 'punch-sources'))->assertForbidden();
        $this->actingAsTenantUser($this->hr)->postJson($this->tenantUrl($this->tenant, 'punch-sources'), ['name' => 'X', 'kind' => 'device'])->assertForbidden();
    });

    it('validates names, kinds and allowlist entries', function (): void {
        $this->actingAsTenantUser($this->admin)
            ->postJson($this->tenantUrl($this->tenant, 'punch-sources'), ['name' => '', 'kind' => 'phone', 'ip_allowlist' => ['10.0.0.0/40', 'not-an-ip']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'kind', 'ip_allowlist.0', 'ip_allowlist.1']);
    });

    it('rotates the key so the old one stops working', function (): void {
        $body = ['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z'];
        $newKey = $this->actingAsTenantUser($this->admin)
            ->postJson($this->tenantUrl($this->tenant, "punch-sources/{$this->source->id}/rotate-key"))
            ->assertOk()
            ->json('key');

        $this->postPunch($body)->assertUnauthorized();
        $this->postPunch($body, $newKey)->assertStatus(202);
    });

    it('updates and deactivates, and deletes only unused sources', function (): void {
        $this->actingAsTenantUser($this->admin)
            ->putJson($this->tenantUrl($this->tenant, "punch-sources/{$this->source->id}"), ['name' => 'Main door', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Main door')->assertJsonPath('data.is_active', false);

        $this->tenant->run(fn () => $this->source->refresh()->update(['is_active' => true]));
        $this->postPunch(['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z'])->assertStatus(202);

        $this->actingAsTenantUser($this->admin)->deleteJson($this->tenantUrl($this->tenant, "punch-sources/{$this->source->id}"))
            ->assertStatus(409)->assertJsonPath('rule', 'punch_source_in_use');

        $unused = $this->tenant->run(fn () => app(PunchSourceService::class)->create(['name' => 'Spare', 'kind' => 'device'])[0]);
        $this->deleteJson($this->tenantUrl($this->tenant, "punch-sources/{$unused->id}"))->assertNoContent();
    });
});

describe('raw punch log', function (): void {
    it('lists punches as received with their state', function (): void {
        $this->punch('E-1', '2026-03-02 08:55');
        $this->punch('BADGE-1', '2026-03-02 09:00');

        $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, 'raw-punches'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.state', 'unmatched')
            ->assertJsonPath('data.1.state', 'matched')
            ->assertJsonPath('data.1.employee.employee_code', 'E-1');

        $this->getJson($this->tenantUrl($this->tenant, 'raw-punches?state=unmatched'))->assertJsonCount(1, 'data');
        $this->getJson($this->tenantUrl($this->tenant, "raw-punches?employee_id={$this->employee->id}"))->assertJsonCount(1, 'data');
        $this->getJson($this->tenantUrl($this->tenant, 'raw-punches?search=badge'))->assertJsonCount(1, 'data');
    });

    it('is not visible to managers or employees', function (): void {
        foreach ([RoleName::DepartmentManager, RoleName::Employee] as $role) {
            $this->actingAsTenantUser($this->createTenantUser($this->tenant, $role))
                ->getJson($this->tenantUrl($this->tenant, 'raw-punches'))
                ->assertForbidden();
        }
    });
});

describe('unmatched punches', function (): void {
    it('links every open punch with the identifier to the employee', function (): void {
        $this->punch('BADGE-7', '2026-03-02 08:57', '2026-03-02 17:02');
        $orphan = $this->tenant->run(fn () => OrphanPunch::query()->firstOrFail());

        $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, 'orphan-punches'))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.employee_identifier', 'BADGE-7');

        $this->postJson($this->tenantUrl($this->tenant, "orphan-punches/{$orphan->id}/link"), ['employee_id' => $this->employee->id])
            ->assertOk()->assertJsonPath('linked', 2);

        $this->tenant->run(function (): void {
            expect(EmployeePunchIdentifier::query()->where('employee_id', $this->employee->id)->value('identifier'))->toBe('BADGE-7')
                ->and(PunchMatch::query()->where('employee_id', $this->employee->id)->count())->toBe(2)
                ->and(OrphanPunch::query()->open()->count())->toBe(0);
        });
        expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Present);

        // Later punches with the badge match on arrival.
        $this->punch('BADGE-7', '2026-03-03 09:00');
        expect($this->tenant->run(fn () => OrphanPunch::query()->count()))->toBe(2);

        $this->postJson($this->tenantUrl($this->tenant, "orphan-punches/{$orphan->id}/link"), ['employee_id' => $this->employee->id])
            ->assertStatus(409);
    });

    it('dismisses a punch and refuses a link to another employee\'s code', function (): void {
        $other = $this->tenant->run(fn () => Employee::factory()->inDepartment($this->department)->create(['employee_code' => 'E-2']));
        $this->punch('VISITOR', '2026-03-02 10:00');
        $this->punch('E-2X', '2026-03-02 10:00');
        [$visitor, $typo] = $this->tenant->run(fn () => OrphanPunch::query()->orderBy('id')->get()->all());

        $this->actingAsTenantUser($this->hr)
            ->postJson($this->tenantUrl($this->tenant, "orphan-punches/{$visitor->id}/dismiss"))
            ->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->getJson($this->tenantUrl($this->tenant, 'orphan-punches?status=dismissed'))->assertJsonCount(1, 'data');

        $this->tenant->run(fn () => EmployeePunchIdentifier::query()->create(['employee_id' => $other->id, 'identifier' => 'E-2X']));
        $this->postJson($this->tenantUrl($this->tenant, "orphan-punches/{$typo->id}/link"), ['employee_id' => $this->employee->id])
            ->assertUnprocessable()->assertJsonValidationErrors('identifier');
    });
});

describe('punch identifiers', function (): void {
    it('adds identifiers, links matching open punches, and keeps them unique', function (): void {
        $this->punch('CARD-55', '2026-03-02 08:59', '2026-03-02 17:00');
        $other = $this->tenant->run(fn () => Employee::factory()->inDepartment($this->department)->create(['employee_code' => 'E-2']));
        $url = $this->tenantUrl($this->tenant, "employees/{$this->employee->id}/punch-identifiers");

        $id = $this->actingAsTenantUser($this->hr)->postJson($url, ['identifier' => 'CARD-55', 'label' => 'Access card'])
            ->assertCreated()->assertJsonPath('data.label', 'Access card')->json('data.id');
        expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Present);

        $this->postJson($url, ['identifier' => 'card-55'])->assertUnprocessable()->assertJsonValidationErrors('identifier');
        $this->postJson($url, ['identifier' => 'E-2'])->assertUnprocessable()->assertJsonValidationErrors('identifier');
        $this->postJson($url, ['identifier' => 'E-1'])->assertUnprocessable()->assertJsonValidationErrors('identifier');
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson($this->tenantUrl($this->tenant, "punch-identifiers/{$id}"))->assertNoContent();
        // Punches already matched stay with the employee.
        expect($this->tenant->run(fn () => PunchMatch::query()->where('employee_id', $this->employee->id)->count()))->toBe(2);

        $this->actingAsTenantUser($this->createTenantUser($this->tenant, RoleName::DepartmentManager))
            ->postJson($this->tenantUrl($this->tenant, "employees/{$other->id}/punch-identifiers"), ['identifier' => 'X'])
            ->assertForbidden();
    });
});
