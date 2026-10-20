<?php

declare(strict_types=1);

use App\Modules\Central\Models\Plan;
use App\Modules\Central\Models\SuperAdmin;
use App\Modules\Core\Enums\ActorType;
use App\Modules\Core\Enums\AuditAction;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records created, updated and deleted with before/after snapshots', function (): void {
    $plan = Plan::factory()->create(['name' => 'Gold']);
    $plan->update(['name' => 'Platinum']);
    $plan->delete();

    $logs = AuditLog::query()->where('auditable_type', 'plan')->orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe([AuditAction::Created, AuditAction::Updated, AuditAction::Deleted])
        ->and($logs[0]->after['name'])->toBe('Gold')
        ->and($logs[1]->before)->toBe(['name' => 'Gold'])
        ->and($logs[1]->after)->toBe(['name' => 'Platinum'])
        ->and($logs[2]->before['name'])->toBe('Platinum')
        ->and($logs[2]->after)->toBeNull();
});

it('never stores passwords, only a redaction marker', function (): void {
    $admin = SuperAdmin::factory()->create();
    $admin->update(['password' => 'a-new-secret-1']);

    $logs = AuditLog::query()->where('auditable_type', 'super_admin')->get();

    expect($logs->firstWhere('action', AuditAction::Created)->after['password'])->toBe(AuditLogger::REDACTED)
        ->and($logs->firstWhere('action', AuditAction::Updated)->after)->toBe(['password' => AuditLogger::REDACTED])
        ->and(json_encode($logs->toArray()))->not->toContain('a-new-secret-1');
});

it('skips updates that only touch ignored attributes', function (): void {
    $admin = SuperAdmin::factory()->create();
    $admin->forceFill(['last_login_at' => now()])->save();

    expect(AuditLog::query()->where('action', AuditAction::Updated)->count())->toBe(0);
});

it('attributes the change to the authenticated actor, or to the system', function (): void {
    Plan::factory()->create();
    expect(AuditLog::query()->latest('id')->first()->actor_type)->toBe(ActorType::System);

    $admin = SuperAdmin::factory()->create(['name' => 'Ops Person']);
    $this->actingAs($admin, 'central');
    auth()->shouldUse('central');

    Plan::factory()->create();
    $log = AuditLog::query()->where('auditable_type', 'plan')->latest('id')->first();

    expect($log->actor_type)->toBe(ActorType::SuperAdmin)
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->actor_name)->toBe('Ops Person');
});

it('writes tenant model audits into the tenant database, not central', function (): void {
    $tenant = $this->createTenant('acme');
    $centralBefore = AuditLog::query()->where('auditable_type', 'user')->count();

    $this->createTenantUser($tenant);

    expect(AuditLog::query()->where('auditable_type', 'user')->count())->toBe($centralBefore)
        ->and($tenant->run(fn () => AuditLog::query()->where('auditable_type', 'user')->count()))->toBeGreaterThanOrEqual(2);
});
