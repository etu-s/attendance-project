<?php

declare(strict_types=1);

use App\Modules\Core\Enums\AuditAction;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = $this->createTenant('acme', ['timezone' => 'Asia/Karachi']);
    $this->admin = $this->tenantAdmin($this->tenant);
});

it('lists audit entries newest first with pagination', function (): void {
    $this->tenant->run(fn () => AuditLog::factory()->count(30)->create());

    $this->actingAsTenantUser($this->admin)
        ->getJson($this->tenantUrl($this->tenant, 'audit-logs?per_page=10'))
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonStructure(['data' => [['id', 'action', 'actor' => ['type', 'id', 'name'], 'subject' => ['type', 'id'], 'before', 'after', 'created_at']]]);
});

it('filters by action and subject', function (): void {
    $this->tenant->run(function (): void {
        AuditLog::factory()->count(3)->create(['action' => AuditAction::Deleted, 'auditable_type' => 'user', 'auditable_id' => '7']);
        AuditLog::factory()->count(2)->create(['action' => AuditAction::Created, 'auditable_type' => 'setting']);
    });

    $this->actingAsTenantUser($this->admin)
        ->getJson($this->tenantUrl($this->tenant, 'audit-logs?action=deleted&subject_type=user&subject_id=7'))
        ->assertOk()
        ->assertJsonPath('meta.total', 3);
});

it('interprets from/to as calendar days in the tenant timezone', function (): void {
    // Asia/Karachi is UTC+5. 2026-03-09 19:30 UTC is already 2026-03-10 00:30 in Karachi.
    $this->tenant->run(function (): void {
        AuditLog::factory()->create(['created_at' => Carbon::parse('2026-03-09 18:59:00', 'UTC'), 'auditable_id' => 'before-midnight']);
        AuditLog::factory()->create(['created_at' => Carbon::parse('2026-03-09 19:30:00', 'UTC'), 'auditable_id' => 'after-midnight']);
        AuditLog::factory()->create(['created_at' => Carbon::parse('2026-03-10 18:59:59', 'UTC'), 'auditable_id' => 'end-of-day']);
        AuditLog::factory()->create(['created_at' => Carbon::parse('2026-03-10 19:00:00', 'UTC'), 'auditable_id' => 'next-day']);
    });

    $ids = $this->actingAsTenantUser($this->admin)
        ->getJson($this->tenantUrl($this->tenant, 'audit-logs?subject_type=user&from=2026-03-10&to=2026-03-10'))
        ->assertOk()
        ->json('data.*.subject.id');

    expect($ids)->toEqualCanonicalizing(['after-midnight', 'end-of-day']);
});

it('rejects an inverted date range', function (): void {
    $this->actingAsTenantUser($this->admin)
        ->getJson($this->tenantUrl($this->tenant, 'audit-logs?from=2026-03-10&to=2026-03-01'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

it('is visible to HR managers but not to employees', function (): void {
    $hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
    $employee = $this->createTenantUser($this->tenant, RoleName::Employee);

    $this->actingAsTenantUser($hr)->getJson($this->tenantUrl($this->tenant, 'audit-logs'))->assertOk();
    $this->actingAsTenantUser($employee)->getJson($this->tenantUrl($this->tenant, 'audit-logs'))->assertForbidden();
});
