<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Central\Models\Plan;
use App\Modules\Central\Models\SuperAdmin;
use App\Modules\Central\Models\Tenant;
use App\Modules\Central\Services\TenantProvisioner;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Tenancy\TenantProvisioningData;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tenant databases are real SQLite files (tenancy needs a file per tenant),
     * kept in a per-process temp folder and removed after every test.
     */
    protected string $tenantDatabaseDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $token = ParallelTesting::token() ?: (string) getmypid();
        $this->tenantDatabaseDirectory = storage_path('framework/testing/tenants-'.$token);
        File::ensureDirectoryExists($this->tenantDatabaseDirectory);

        config([
            'tenancy.database.sqlite_directory' => $this->tenantDatabaseDirectory,
            'ams.backups.path' => storage_path('framework/testing/backups-'.$token),
        ]);

        $this->withHeader('Accept', 'application/json');
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // Windows cannot delete a SQLite file while a PDO handle is open.
        DB::purge('tenant');
        gc_collect_cycles();

        File::deleteDirectory($this->tenantDatabaseDirectory);
        File::deleteDirectory((string) config('ams.backups.path'));

        parent::tearDown();
    }

    /**
     * Provisions a real tenant (file, migrations, defaults, Tenant Admin) and
     * returns in central context.
     *
     * @param  array<string, mixed>  $overrides  TenantProvisioningData fields
     */
    protected function createTenant(string $subdomain = 'acme', array $overrides = []): Tenant
    {
        $tenant = app(TenantProvisioner::class)->provision(new TenantProvisioningData(...array_merge([
            'name' => ucfirst($subdomain).' Inc',
            'subdomain' => $subdomain,
            'planId' => Plan::factory()->create()->id,
            'timezone' => 'UTC',
            'adminName' => ucfirst($subdomain).' Admin',
            'adminEmail' => "admin@{$subdomain}.test",
            'adminPassword' => 'password1',
        ], $overrides)));

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $tenant;
    }

    /**
     * Creates a user inside the tenant and returns it (central context restored).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createTenantUser(Tenant $tenant, RoleName $role = RoleName::Employee, array $attributes = []): User
    {
        return $tenant->run(fn () => User::factory()->withRole($role)->create($attributes)->load('roles'));
    }

    protected function tenantAdmin(Tenant $tenant): User
    {
        return $tenant->run(fn () => User::query()->with('roles')->where('email', 'admin@'.$tenant->subdomain().'.test')->firstOrFail());
    }

    /**
     * Emulates a request boundary. In production every request starts with a
     * fresh tenancy state; in a test the app persists, and a tenant left
     * initialized would keep a stale tenant model (e.g. an old status).
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        if (tenancy()->initialized) {
            tenancy()->end();
        }

        return $response;
    }

    /**
     * Authenticates as a tenant user (sanctum checks the `web` guard). Guards are
     * reset first: the sanctum guard caches the user it resolved on an earlier request.
     */
    protected function actingAsTenantUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    protected function actingAsSuperAdmin(?SuperAdmin $admin = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($admin ?? SuperAdmin::factory()->create(), 'central');
    }

    /**
     * Full URL on a tenant subdomain, e.g. tenantUrl('acme', 'users') =>
     * http://acme.localhost/api/v1/users
     */
    protected function tenantUrl(Tenant|string $tenant, string $path): string
    {
        $subdomain = $tenant instanceof Tenant ? $tenant->subdomain() : $tenant;

        return "http://{$subdomain}.localhost/api/v1/".ltrim($path, '/');
    }

    protected function centralUrl(string $path): string
    {
        return 'http://localhost/api/v1/central/'.ltrim($path, '/');
    }

    /**
     * Marks requests as coming from the SPA so Sanctum starts a session
     * (needed for login/logout and session-based tests).
     */
    protected function fromSpa(Tenant|string|null $tenant = null): static
    {
        $host = match (true) {
            $tenant instanceof Tenant => $tenant->subdomain().'.localhost:5173',
            is_string($tenant) => $tenant.'.localhost:5173',
            default => 'localhost:5173',
        };

        return $this->withHeaders(['Origin' => "http://{$host}", 'Referer' => "http://{$host}/"]);
    }
}
