<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The last-admin rule is defence in depth: the API already stops self-demotion
 * and only admins may modify admins. These tests call the service directly to
 * prove the rule holds even if a caller skips those checks.
 */
beforeEach(function (): void {
    $this->tenant = $this->createTenant('acme');
});

it('refuses to demote the last active Tenant Admin', function (): void {
    $this->tenant->run(function (): void {
        $admin = User::query()->with('roles')->firstOrFail();
        $hr = User::factory()->withRole(RoleName::HrManager)->create()->load('roles');

        expect(fn () => app(UserService::class)->update($admin, ['role' => RoleName::Employee->value], $hr))
            ->toThrow(BusinessRuleException::class, 'A workspace must keep at least one active Tenant Admin.');

        expect($admin->fresh()->load('roles')->isTenantAdmin())->toBeTrue();
    });
});

it('refuses to deactivate the last active Tenant Admin', function (): void {
    $this->tenant->run(function (): void {
        $admin = User::query()->with('roles')->firstOrFail();
        $hr = User::factory()->withRole(RoleName::HrManager)->create()->load('roles');

        expect(fn () => app(UserService::class)->update($admin, ['status' => 'inactive'], $hr))
            ->toThrow(BusinessRuleException::class);
    });
});

it('does not count inactive admins as remaining admins', function (): void {
    $this->tenant->run(function (): void {
        $admin = User::query()->with('roles')->firstOrFail();
        User::factory()->withRole(RoleName::TenantAdmin)->inactive()->create();
        $hr = User::factory()->withRole(RoleName::HrManager)->create()->load('roles');

        expect(fn () => app(UserService::class)->delete($admin, $hr))->toThrow(BusinessRuleException::class);
    });
});

it('refuses to let a non-admin grant Tenant Admin even when called directly', function (): void {
    $this->tenant->run(function (): void {
        $hr = User::factory()->withRole(RoleName::HrManager)->create()->load('roles');

        expect(fn () => app(UserService::class)->create([
            'name' => 'Sneaky', 'email' => 'sneaky@acme.test', 'password' => 'secret123', 'role' => 'tenant-admin',
        ], $hr))->toThrow(BusinessRuleException::class, 'Only a Tenant Admin can grant the Tenant Admin role.');
    });
});

it('keeps exactly one role per user', function (): void {
    $this->tenant->run(function (): void {
        $admin = User::query()->with('roles')->firstOrFail();
        $user = User::factory()->withRole(RoleName::Employee)->create()->load('roles');

        app(UserService::class)->update($user, ['role' => RoleName::HrManager->value], $admin);

        expect($user->fresh()->roles->pluck('name')->all())->toBe(['hr-manager']);
    });
});
