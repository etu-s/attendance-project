<?php

declare(strict_types=1);

use App\Modules\Core\Enums\AuditAction;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = $this->createTenant('acme');
    $this->admin = $this->tenantAdmin($this->tenant);
});

describe('index', function (): void {
    it('paginates, searches, filters and sorts users', function (): void {
        $this->createTenantUser($this->tenant, RoleName::HrManager, ['name' => 'Zoe Hr', 'email' => 'zoe@acme.test']);
        $this->createTenantUser($this->tenant, RoleName::Employee, ['name' => 'Adam Employee', 'email' => 'adam@acme.test']);
        $this->createTenantUser($this->tenant, RoleName::Employee, ['name' => 'Off Employee', 'status' => 'inactive']);

        $this->actingAsTenantUser($this->admin);

        $this->getJson($this->tenantUrl($this->tenant, 'users?sort=-name&per_page=2'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Zoe Hr')
            ->assertJsonPath('meta.total', 4);

        $this->getJson($this->tenantUrl($this->tenant, 'users?search=ZOE'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.role', 'hr-manager');

        $this->getJson($this->tenantUrl($this->tenant, 'users?role=employee&status=active'))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'adam@acme.test');
    });

    it('loads roles without N+1 queries', function (): void {
        foreach (range(1, 15) as $i) {
            $this->createTenantUser($this->tenant);
        }

        $this->actingAsTenantUser($this->admin);
        $this->getJson($this->tenantUrl($this->tenant, 'users?per_page=5'))->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson($this->tenantUrl($this->tenant, 'users?per_page=50'))->assertOk()->assertJsonCount(16, 'data');

        // Same count whether 5 or 50 rows come back: roles are eager loaded.
        $queriesForFifty = $queries;
        $queries = 0;
        $this->getJson($this->tenantUrl($this->tenant, 'users?per_page=5'))->assertOk();

        expect($queriesForFifty)->toBe($queries);
    });

    it('is forbidden to employees and department managers', function (RoleName $role): void {
        $user = $this->createTenantUser($this->tenant, $role);

        $this->actingAsTenantUser($user)->getJson($this->tenantUrl($this->tenant, 'users'))->assertForbidden();
    })->with([RoleName::Employee, RoleName::DepartmentManager]);
});

describe('store', function (): void {
    it('creates a user with a role and audits it', function (): void {
        $this->actingAsTenantUser($this->admin)
            ->postJson($this->tenantUrl($this->tenant, 'users'), [
                'name' => 'New Person',
                'email' => 'New@Acme.test',
                'password' => 'secret123',
                'role' => 'hr-manager',
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'new@acme.test')
            ->assertJsonPath('data.role', 'hr-manager')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonMissingPath('data.password');

        $this->tenant->run(function (): void {
            $user = User::query()->where('email', 'new@acme.test')->firstOrFail();
            $logs = AuditLog::query()->where('auditable_type', 'user')->where('auditable_id', (string) $user->id)->get();

            expect($logs->pluck('action')->all())->toContain(AuditAction::Created, AuditAction::RoleChanged)
                ->and($logs->firstWhere('action', AuditAction::Created)->after['password'])->toBe(AuditLogger::REDACTED);
        });
    });

    it('lets HR managers create users but not Tenant Admins', function (): void {
        $hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
        $payload = ['name' => 'X', 'email' => 'x@acme.test', 'password' => 'secret123'];

        $this->actingAsTenantUser($hr)
            ->postJson($this->tenantUrl($this->tenant, 'users'), $payload + ['role' => 'tenant-admin'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.role.0', 'You are not allowed to assign this role.');

        $this->actingAsTenantUser($hr)
            ->postJson($this->tenantUrl($this->tenant, 'users'), $payload + ['role' => 'employee'])
            ->assertCreated();
    });

    it('rejects duplicate emails case-insensitively, including soft-deleted users', function (): void {
        $gone = $this->createTenantUser($this->tenant, RoleName::Employee, ['email' => 'gone@acme.test']);
        $this->tenant->run(fn () => $gone->delete());

        $this->actingAsTenantUser($this->admin);

        foreach (['ADMIN@acme.test', 'gone@acme.test'] as $email) {
            $this->postJson($this->tenantUrl($this->tenant, 'users'), [
                'name' => 'Dup', 'email' => $email, 'password' => 'secret123', 'role' => 'employee',
            ])->assertUnprocessable()->assertJsonValidationErrors('email');
        }
    });

    it('validates password strength and role values', function (): void {
        $this->actingAsTenantUser($this->admin)
            ->postJson($this->tenantUrl($this->tenant, 'users'), [
                'name' => 'Weak', 'email' => 'weak@acme.test', 'password' => 'short', 'role' => 'overlord',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password', 'role']);
    });

    it('is forbidden without users.create', function (): void {
        $employee = $this->createTenantUser($this->tenant, RoleName::Employee);

        $this->actingAsTenantUser($employee)
            ->postJson($this->tenantUrl($this->tenant, 'users'), ['name' => 'X', 'email' => 'x@acme.test', 'password' => 'secret123', 'role' => 'employee'])
            ->assertForbidden();
    });
});

describe('show and update', function (): void {
    it('shows a user', function (): void {
        $user = $this->createTenantUser($this->tenant, RoleName::Employee, ['email' => 'show@acme.test']);

        $this->actingAsTenantUser($this->admin)
            ->getJson($this->tenantUrl($this->tenant, "users/{$user->id}"))
            ->assertOk()
            ->assertJsonPath('data.email', 'show@acme.test');
    });

    it('updates profile, role and status and audits the role change', function (): void {
        $user = $this->createTenantUser($this->tenant, RoleName::Employee);

        $this->actingAsTenantUser($this->admin)
            ->putJson($this->tenantUrl($this->tenant, "users/{$user->id}"), [
                'name' => 'Renamed',
                'role' => 'hr-manager',
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.role', 'hr-manager')
            ->assertJsonPath('data.status', 'inactive');

        $this->tenant->run(function () use ($user): void {
            $log = AuditLog::query()->where('action', AuditAction::RoleChanged)->where('auditable_id', (string) $user->id)->latest('id')->first();
            expect($log->before)->toBe(['role' => 'employee'])
                ->and($log->after)->toBe(['role' => 'hr-manager'])
                ->and($log->actor_name)->toBe('Acme Admin');
        });
    });

    it('keeps the password when it is left empty and changes it when given', function (): void {
        $user = $this->createTenantUser($this->tenant, RoleName::Employee, ['email' => 'pw@acme.test']);
        $this->actingAsTenantUser($this->admin);

        $this->putJson($this->tenantUrl($this->tenant, "users/{$user->id}"), ['name' => 'Same Pw', 'password' => ''])->assertOk();
        $this->tenant->run(fn () => expect(Hash::check('password1', User::query()->find($user->id)->password))->toBeTrue());

        $this->putJson($this->tenantUrl($this->tenant, "users/{$user->id}"), ['password' => 'brand-new-9'])->assertOk();
        $this->tenant->run(fn () => expect(Hash::check('brand-new-9', User::query()->find($user->id)->password))->toBeTrue());
    });

    it('stops HR managers from editing Tenant Admins or promoting anyone to Tenant Admin', function (): void {
        $hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
        $employee = $this->createTenantUser($this->tenant, RoleName::Employee);

        $this->actingAsTenantUser($hr)
            ->putJson($this->tenantUrl($this->tenant, "users/{$this->admin->id}"), ['password' => 'takeover123'])
            ->assertForbidden();

        $this->actingAsTenantUser($hr)
            ->putJson($this->tenantUrl($this->tenant, "users/{$employee->id}"), ['role' => 'tenant-admin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    });

    it('stops users from changing their own role or status', function (): void {
        $this->actingAsTenantUser($this->admin)
            ->putJson($this->tenantUrl($this->tenant, "users/{$this->admin->id}"), ['role' => 'employee'])
            ->assertForbidden()
            ->assertJsonPath('rule', 'self_modification');

        $this->actingAsTenantUser($this->admin)
            ->putJson($this->tenantUrl($this->tenant, "users/{$this->admin->id}"), ['status' => 'inactive'])
            ->assertForbidden();

        // Updating your own name is fine.
        $this->actingAsTenantUser($this->admin)
            ->putJson($this->tenantUrl($this->tenant, "users/{$this->admin->id}"), ['name' => 'Still Admin', 'role' => 'tenant-admin'])
            ->assertOk();
    });

    it('lets a Tenant Admin demote another admin while one remains', function (): void {
        $second = $this->createTenantUser($this->tenant, RoleName::TenantAdmin);

        $this->actingAsTenantUser($this->admin)
            ->putJson($this->tenantUrl($this->tenant, "users/{$second->id}"), ['role' => 'employee'])
            ->assertOk();
    });
});

describe('destroy', function (): void {
    it('soft deletes a user and revokes their API tokens', function (): void {
        $user = $this->createTenantUser($this->tenant, RoleName::Employee);
        $this->tenant->run(fn () => $user->createToken('device'));

        $this->actingAsTenantUser($this->admin)
            ->deleteJson($this->tenantUrl($this->tenant, "users/{$user->id}"))
            ->assertNoContent();

        $this->tenant->run(function () use ($user): void {
            expect(User::query()->find($user->id))->toBeNull()
                ->and(User::withTrashed()->find($user->id))->not->toBeNull()
                ->and(DB::table('personal_access_tokens')->count())->toBe(0);
        });
    });

    it('does not let users delete themselves', function (): void {
        $this->actingAsTenantUser($this->admin)
            ->deleteJson($this->tenantUrl($this->tenant, "users/{$this->admin->id}"))
            ->assertForbidden();
    });

    it('is forbidden to HR managers (no users.delete)', function (): void {
        $hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
        $employee = $this->createTenantUser($this->tenant, RoleName::Employee);

        $this->actingAsTenantUser($hr)
            ->deleteJson($this->tenantUrl($this->tenant, "users/{$employee->id}"))
            ->assertForbidden();
    });

    it('returns 404 for users that do not exist in this tenant', function (): void {
        $this->actingAsTenantUser($this->admin)
            ->getJson($this->tenantUrl($this->tenant, 'users/999'))
            ->assertNotFound();
    });
});
