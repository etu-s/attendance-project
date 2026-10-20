<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Permissions\PermissionCatalog;

it('grants every permission to Tenant Admin', function (): void {
    $catalog = new PermissionCatalog;
    $catalog->register('users.view', [RoleName::HrManager]);
    $catalog->register('users.delete');

    expect($catalog->permissionsFor(RoleName::TenantAdmin))->toBe(['users.view', 'users.delete']);
});

it('grants other roles only what was registered for them', function (): void {
    $catalog = new PermissionCatalog;
    $catalog->register('users.view', [RoleName::HrManager]);
    $catalog->register('users.delete');

    expect($catalog->permissionsFor(RoleName::HrManager))->toBe(['users.view'])
        ->and($catalog->permissionsFor(RoleName::Employee))->toBe([]);
});

it('rejects malformed and duplicate permission names', function (): void {
    $catalog = new PermissionCatalog;
    $catalog->register('users.view');

    expect(fn () => $catalog->register('users.view'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $catalog->register('Users View'))->toThrow(InvalidArgumentException::class);
});

it('registers the foundation permissions at boot', function (): void {
    expect(app(PermissionCatalog::class)->permissions())->toContain(
        'users.view', 'users.create', 'users.update', 'users.delete',
        'settings.view', 'settings.update', 'audit-logs.view',
    );
});
