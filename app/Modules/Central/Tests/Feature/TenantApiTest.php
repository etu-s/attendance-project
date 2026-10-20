<?php

declare(strict_types=1);

use App\Modules\Central\Enums\TenantStatus;
use App\Modules\Central\Models\Domain;
use App\Modules\Central\Models\Plan;
use App\Modules\Central\Models\Tenant;
use App\Modules\Core\Tenancy\TenantSqliteDatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAsSuperAdmin();
    $this->plan = Plan::factory()->create();
});

/**
 * @return array<string, mixed>
 */
function tenantPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Initech',
        'subdomain' => 'initech',
        'plan_id' => test()->plan->id,
        'timezone' => 'Europe/London',
        'admin_name' => 'Bill Lumbergh',
        'admin_email' => 'bill@initech.test',
        'admin_password' => 'tps-report1',
        'admin_password_confirmation' => 'tps-report1',
    ], $overrides);
}

function tenantFile(Tenant $tenant): string
{
    return app(TenantSqliteDatabaseManager::class)->path($tenant->database()->getName());
}

it('provisions a tenant whose admin can log in on its subdomain', function (): void {
    $response = $this->postJson($this->centralUrl('tenants'), tenantPayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Initech')
        ->assertJsonPath('data.subdomain', 'initech')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.plan.id', $this->plan->id)
        ->assertJsonMissingPath('data.data');

    $tenant = Tenant::query()->findOrFail($response->json('data.id'));
    expect(file_exists(tenantFile($tenant)))->toBeTrue();

    auth()->forgetGuards();
    $this->fromSpa('initech')
        ->postJson($this->tenantUrl('initech', 'auth/login'), ['email' => 'bill@initech.test', 'password' => 'tps-report1'])
        ->assertOk()
        ->assertJsonPath('data.user.role', 'tenant-admin')
        ->assertJsonPath('data.workspace.timezone', 'Europe/London');
});

it('normalizes the subdomain to lowercase', function (): void {
    $this->postJson($this->centralUrl('tenants'), tenantPayload(['subdomain' => '  InitTech ']))
        ->assertCreated()
        ->assertJsonPath('data.subdomain', 'inittech');
});

it('validates tenant input', function (array $overrides, string $field): void {
    Domain::query()->create(['domain' => 'taken', 'tenant_id' => Tenant::factory()->create()->id]);
    $inactive = Plan::factory()->inactive()->create();

    $payload = tenantPayload($overrides);
    if (($payload['plan_id'] ?? null) === 'inactive') {
        $payload['plan_id'] = $inactive->id;
    }

    $this->postJson($this->centralUrl('tenants'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'taken subdomain' => [['subdomain' => 'taken'], 'subdomain'],
    'reserved subdomain' => [['subdomain' => 'admin'], 'subdomain'],
    'invalid characters' => [['subdomain' => 'my_company'], 'subdomain'],
    'leading hyphen' => [['subdomain' => '-acme'], 'subdomain'],
    'dots' => [['subdomain' => 'a.b'], 'subdomain'],
    'too short' => [['subdomain' => 'a'], 'subdomain'],
    'inactive plan' => [['plan_id' => 'inactive'], 'plan_id'],
    'bad timezone' => [['timezone' => 'Nowhere/Land'], 'timezone'],
    'weak password' => [['admin_password' => 'short', 'admin_password_confirmation' => 'short'], 'admin_password'],
    'unconfirmed password' => [['admin_password_confirmation' => 'different1'], 'admin_password'],
    'bad admin email' => [['admin_email' => 'nope'], 'admin_email'],
]);

it('lists tenants with search, status filter and sorting', function (): void {
    $this->createTenant('acme');
    $globex = $this->createTenant('globex');
    $globex->update(['status' => TenantStatus::Suspended]);

    $this->getJson($this->centralUrl('tenants?search=glob'))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.subdomain', 'globex');

    $this->getJson($this->centralUrl('tenants?status=active'))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.subdomain', 'acme');

    $this->getJson($this->centralUrl('tenants?sort=name'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Acme Inc');
});

it('shows and updates a tenant but never changes its subdomain', function (): void {
    $tenant = $this->createTenant('acme');
    $other = Plan::factory()->create();

    $this->getJson($this->centralUrl("tenants/{$tenant->id}"))->assertOk()->assertJsonPath('data.subdomain', 'acme');

    $this->putJson($this->centralUrl("tenants/{$tenant->id}"), ['name' => 'Acme Global', 'plan_id' => $other->id, 'subdomain' => 'hijack'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Acme Global')
        ->assertJsonPath('data.plan.id', $other->id)
        ->assertJsonPath('data.subdomain', 'acme');
});

it('suspends and reactivates a tenant, blocking its routes while suspended', function (): void {
    $tenant = $this->createTenant('acme');

    $this->postJson($this->centralUrl("tenants/{$tenant->id}/suspend"))->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->getJson($this->tenantUrl('acme', 'health'))->assertForbidden();

    $this->postJson($this->centralUrl("tenants/{$tenant->id}/suspend"))->assertStatus(409);

    $this->postJson($this->centralUrl("tenants/{$tenant->id}/activate"))->assertOk()->assertJsonPath('data.status', 'active');
    $this->getJson($this->tenantUrl('acme', 'health'))->assertOk();

    $this->postJson($this->centralUrl("tenants/{$tenant->id}/activate"))->assertStatus(409);
});

it('only deletes suspended tenants, with confirmation, after taking a backup', function (): void {
    $tenant = $this->createTenant('acme');
    $file = tenantFile($tenant);

    $this->deleteJson($this->centralUrl("tenants/{$tenant->id}"), ['confirm' => 'acme'])
        ->assertStatus(409)
        ->assertJsonPath('rule', 'tenant_not_suspended');

    $tenant->update(['status' => TenantStatus::Suspended]);

    $this->deleteJson($this->centralUrl("tenants/{$tenant->id}"), ['confirm' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirm');

    $this->deleteJson($this->centralUrl("tenants/{$tenant->id}"), ['confirm' => 'acme'])->assertNoContent();

    expect(Tenant::query()->find($tenant->id))->toBeNull()
        ->and(Domain::query()->where('domain', 'acme')->exists())->toBeFalse()
        ->and(file_exists($file))->toBeFalse()
        ->and(collect(File::allFiles((string) config('ams.backups.path')))->map->getFilename()->all())
        ->toContain($tenant->id.'.sqlite');
});

it('requires a super admin for every tenant endpoint', function (): void {
    $tenant = $this->createTenant('acme');
    $this->app['auth']->forgetGuards();
    $this->app['auth']->guard('central')->logout();

    $this->getJson($this->centralUrl('tenants'))->assertUnauthorized();
    $this->postJson($this->centralUrl('tenants'), tenantPayload())->assertUnauthorized();
    $this->deleteJson($this->centralUrl("tenants/{$tenant->id}"), ['confirm' => 'acme'])->assertUnauthorized();
});
