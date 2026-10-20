<?php

declare(strict_types=1);

use App\Modules\Central\Models\Tenant;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Http\Middleware\ScopeSessionToTenant;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Core\Tenancy\TenantSqliteDatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
});

/**
 * Opens a tenant's SQLite file directly, bypassing the application, so the
 * assertion is about what is physically stored in that file.
 */
function rawTenantPdo(Tenant $tenant): PDO
{
    $path = app(TenantSqliteDatabaseManager::class)->path($tenant->database()->getName());

    return new PDO('sqlite:'.$path);
}

it('stores each tenant in its own SQLite file', function (): void {
    $manager = app(TenantSqliteDatabaseManager::class);
    $acmeFile = $manager->path($this->acme->database()->getName());
    $globexFile = $manager->path($this->globex->database()->getName());

    expect($acmeFile)->not->toBe($globexFile)
        ->and(file_exists($acmeFile))->toBeTrue()
        ->and(file_exists($globexFile))->toBeTrue()
        ->and(basename($acmeFile))->toBe($this->acme->getTenantKey().'.sqlite');
});

it('keeps users, settings and audit logs of tenant B out of tenant A\'s file', function (): void {
    $this->createTenantUser($this->globex, RoleName::HrManager, ['email' => 'secret@globex.test', 'name' => 'Globex Secret']);
    $this->globex->run(fn () => app(SettingsService::class)->update(['general.timezone' => 'America/New_York']));

    $acme = rawTenantPdo($this->acme);

    $leakedUsers = $acme->query("select count(*) from users where email like '%globex%' or name like '%Globex%'")->fetchColumn();
    $leakedAudit = $acme->query("select count(*) from audit_logs where after like '%globex%' or after like '%New_York%'")->fetchColumn();
    $timezone = $acme->query("select value from settings where key = 'general.timezone'")->fetchColumn();

    expect((int) $leakedUsers)->toBe(0)
        ->and((int) $leakedAudit)->toBe(0)
        ->and(json_decode((string) $timezone))->toBe('UTC');

    $globex = rawTenantPdo($this->globex);
    expect((int) $globex->query("select count(*) from users where email = 'secret@globex.test'")->fetchColumn())->toBe(1);
});

it('keeps tenant tables out of the central database', function (): void {
    $tables = collect(DB::connection('central')->select("select name from sqlite_master where type = 'table'"))->pluck('name');

    expect($tables)->toContain('tenants', 'domains', 'plans', 'super_admins')
        ->not->toContain('users', 'settings', 'roles', 'permissions');
});

it('lists only the current tenant\'s users through the API', function (): void {
    $this->createTenantUser($this->globex, RoleName::Employee, ['email' => 'only-globex@globex.test']);

    $this->actingAsTenantUser($this->tenantAdmin($this->acme))
        ->getJson($this->tenantUrl($this->acme, 'users'))
        ->assertOk()
        ->assertJsonMissing(['email' => 'only-globex@globex.test'])
        ->assertJsonPath('meta.total', 1);
});

it('does not let a session from one tenant authenticate on another', function (): void {
    // Same user id (1 = each tenant's first admin) exists in both tenants,
    // which is exactly what a replayed session would map onto.
    $login = $this->fromSpa($this->acme)->postJson($this->tenantUrl($this->acme, 'auth/login'), [
        'email' => 'admin@acme.test',
        'password' => 'password1',
    ])->assertOk();

    $cookieName = config('session.cookie');
    $sessionId = $login->getCookie($cookieName, false)?->getValue();
    expect($sessionId)->not->toBeNull();

    $replay = function (Tenant $tenant) use ($cookieName, $sessionId) {
        // Drop in-memory guard state so each request must trust the session alone.
        auth()->forgetGuards();

        return $this->fromSpa($tenant)
            ->withUnencryptedCookie($cookieName, $sessionId)
            ->getJson($this->tenantUrl($tenant, 'auth/me'));
    };

    // Control: the cookie is valid and authenticates on the tenant that issued it.
    $replay($this->acme)->assertOk()->assertJsonPath('data.user.email', 'admin@acme.test');

    // Replayed on another tenant it must not authenticate (globex also has user id 1).
    $replay($this->globex)->assertUnauthorized();

    // And the session was invalidated, not merely ignored: it is now dead on acme too.
    $replay($this->acme)->assertUnauthorized();
});

it('rejects a replayed session even when both users share the same password hash', function (): void {
    // Sanctum's AuthenticateSession compares password hashes, which normally
    // differ between tenants by salt alone. Copying the hash removes that safety
    // net, so only ScopeSessionToTenant stands between the tenants here.
    $acmeHash = $this->acme->run(fn () => DB::table('users')->where('id', 1)->value('password'));
    $this->globex->run(fn () => DB::table('users')->where('id', 1)->update(['password' => $acmeHash]));

    $login = $this->fromSpa($this->acme)->postJson($this->tenantUrl($this->acme, 'auth/login'), [
        'email' => 'admin@acme.test',
        'password' => 'password1',
    ])->assertOk();

    $cookieName = config('session.cookie');
    $sessionId = $login->getCookie($cookieName, false)?->getValue();
    auth()->forgetGuards();

    $this->fromSpa($this->globex)
        ->withUnencryptedCookie($cookieName, $sessionId)
        ->getJson($this->tenantUrl($this->globex, 'auth/me'))
        ->assertUnauthorized();
});

it('binds new sessions to the tenant they were created on', function (): void {
    $this->fromSpa($this->acme)->getJson($this->tenantUrl($this->acme, 'health'))->assertOk();

    expect(session(ScopeSessionToTenant::SESSION_KEY))->toBe($this->acme->getTenantKey());
});

it('keeps spatie permission caches separate per tenant', function (): void {
    $acmeHr = $this->createTenantUser($this->acme, RoleName::HrManager);
    $globexHr = $this->createTenantUser($this->globex, RoleName::HrManager);

    // Warm both caches, then revoke users.view from HR in acme only.
    $this->actingAsTenantUser($acmeHr)->getJson($this->tenantUrl($this->acme, 'users'))->assertOk();
    $this->actingAsTenantUser($globexHr)->getJson($this->tenantUrl($this->globex, 'users'))->assertOk();

    $this->acme->run(fn () => Role::findByName(RoleName::HrManager->value, 'web')->revokePermissionTo('users.view'));

    $this->actingAsTenantUser($acmeHr)->getJson($this->tenantUrl($this->acme, 'users'))->assertForbidden();
    $this->actingAsTenantUser($globexHr)->getJson($this->tenantUrl($this->globex, 'users'))->assertOk();
});

it('answers 404 for unknown subdomains without naming them', function (): void {
    $this->getJson($this->tenantUrl('initech', 'health'))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Workspace not found.']);
});

it('does not serve tenant routes on the central domain', function (): void {
    $this->getJson('http://localhost/api/v1/health')->assertNotFound();
});

it('does not serve central routes on a tenant subdomain', function (): void {
    $this->actingAsSuperAdmin()
        ->getJson('http://acme.localhost/api/v1/central/tenants')
        ->assertNotFound();
});

it('does not let a tenant user use central routes', function (): void {
    $this->actingAsTenantUser($this->tenantAdmin($this->acme))
        ->getJson($this->centralUrl('tenants'))
        ->assertUnauthorized();
});
