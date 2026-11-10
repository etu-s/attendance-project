<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Enums\SettingType;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Permissions\PermissionCatalog;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingsRegistry;
use App\Modules\Identity\Services\PermissionSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = $this->createTenant('acme');
});

it('grants roles exactly the catalog permissions', function (): void {
    $this->tenant->run(function (): void {
        $catalog = app(PermissionCatalog::class);

        foreach (RoleName::cases() as $role) {
            expect(Role::findByName($role->value, 'web')->permissions->pluck('name')->sort()->values()->all())
                ->toBe(collect($catalog->permissionsFor($role))->sort()->values()->all());
        }
    });
});

it('is idempotent', function (): void {
    $this->tenant->run(function (): void {
        $before = Permission::query()->count();

        app(PermissionSynchronizer::class)->sync();
        app(PermissionSynchronizer::class)->sync();

        expect(Permission::query()->count())->toBe($before)
            ->and(Role::query()->count())->toBe(count(RoleName::cases()));
    });
});

it('applies newly registered permissions and settings via tenants:seed without touching existing values', function (): void {
    $this->tenant->run(fn () => app(SettingsService::class)->update(['general.week_start' => 0]));

    app(PermissionCatalog::class)->register('sandbox.view', [RoleName::HrManager]);
    app(SettingsRegistry::class)->register(new SettingDefinition('sandbox.default_range', SettingType::Integer, 30, 'Default range'));

    $this->artisan('tenants:seed', ['--force' => true])->assertSuccessful();

    $this->tenant->run(function (): void {
        expect(Role::findByName('hr-manager', 'web')->hasPermissionTo('sandbox.view'))->toBeTrue()
            ->and(app(SettingsService::class)->get('general.week_start'))->toBe(0)
            ->and(Setting::query()->where('key', 'sandbox.default_range')->exists())->toBeTrue();
    });
});
