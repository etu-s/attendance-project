<?php

declare(strict_types=1);

use App\Modules\Central\Models\Domain;
use App\Modules\Central\Models\Plan;
use App\Modules\Central\Models\Tenant;
use App\Modules\Central\Services\TenantProvisioner;
use App\Modules\Core\Events\TenantDatabaseProvisioned;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Core\Tenancy\TenantProvisioningData;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('removes the tenant row, domain and file when a provisioning step fails', function (): void {
    Event::listen(TenantDatabaseProvisioned::class, function (): void {
        throw new RuntimeException('module seeding failed');
    });

    $data = new TenantProvisioningData(
        name: 'Broken Co',
        subdomain: 'broken',
        planId: Plan::factory()->create()->id,
        timezone: 'UTC',
        adminName: 'Admin',
        adminEmail: 'admin@broken.test',
        adminPassword: 'password1',
    );

    expect(fn () => app(TenantProvisioner::class)->provision($data))->toThrow(RuntimeException::class, 'module seeding failed');

    expect(Tenant::query()->count())->toBe(0)
        ->and(Domain::query()->count())->toBe(0)
        ->and(File::files($this->tenantDatabaseDirectory))->toBeEmpty()
        ->and(tenancy()->initialized)->toBeFalse();
});

it('seeds roles, settings and the first admin through module listeners', function (): void {
    $tenant = $this->createTenant('acme', ['timezone' => 'Asia/Dubai']);

    $tenant->run(function (): void {
        expect(Role::query()->pluck('name')->sort()->values()->all())
            ->toBe(['department-manager', 'employee', 'hr-manager', 'tenant-admin'])
            ->and(app(SettingsService::class)->get('general.timezone'))->toBe('Asia/Dubai')
            ->and(User::query()->count())->toBe(1);
    });
});
