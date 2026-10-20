<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = $this->createTenant('acme', ['timezone' => 'Asia/Karachi']);
});

it('logs a tenant user in and returns permissions and workspace preferences', function (): void {
    $response = $this->fromSpa($this->tenant)->postJson($this->tenantUrl($this->tenant, 'auth/login'), [
        'email' => 'ADMIN@acme.test',
        'password' => 'password1',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.user.email', 'admin@acme.test')
        ->assertJsonPath('data.user.role', RoleName::TenantAdmin->value)
        ->assertJsonPath('data.workspace.name', 'Acme Inc')
        ->assertJsonPath('data.workspace.timezone', 'Asia/Karachi')
        ->assertJsonFragment(['users.create'])
        ->assertJsonFragment(['settings.update']);

    $this->tenant->run(fn () => expect(User::query()->first()->last_login_at)->not->toBeNull());
});

it('rejects a wrong password with a validation error', function (): void {
    $this->fromSpa($this->tenant)
        ->postJson($this->tenantUrl($this->tenant, 'auth/login'), ['email' => 'admin@acme.test', 'password' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('rejects deactivated users even with the right password', function (): void {
    $this->createTenantUser($this->tenant, RoleName::Employee, ['email' => 'off@acme.test', 'status' => 'inactive']);

    $this->fromSpa($this->tenant)
        ->postJson($this->tenantUrl($this->tenant, 'auth/login'), ['email' => 'off@acme.test', 'password' => 'password1'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'This account is deactivated. Contact your administrator.');

    expect(auth('web')->check())->toBeFalse();
});

it('rejects soft-deleted users', function (): void {
    $user = $this->createTenantUser($this->tenant, RoleName::Employee, ['email' => 'gone@acme.test']);
    $this->tenant->run(fn () => $user->delete());

    $this->fromSpa($this->tenant)
        ->postJson($this->tenantUrl($this->tenant, 'auth/login'), ['email' => 'gone@acme.test', 'password' => 'password1'])
        ->assertUnprocessable();
});

it('does not accept credentials from another tenant', function (): void {
    $this->createTenant('globex');

    $this->fromSpa('globex')
        ->postJson($this->tenantUrl('globex', 'auth/login'), ['email' => 'admin@acme.test', 'password' => 'password1'])
        ->assertUnprocessable();
});

it('rate limits login attempts', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->fromSpa($this->tenant)
            ->postJson($this->tenantUrl($this->tenant, 'auth/login'), ['email' => 'admin@acme.test', 'password' => 'wrong'])
            ->assertUnprocessable();
    }

    $this->fromSpa($this->tenant)
        ->postJson($this->tenantUrl($this->tenant, 'auth/login'), ['email' => 'admin@acme.test', 'password' => 'password1'])
        ->assertTooManyRequests();
});

it('returns the current user from me and 401 when unauthenticated', function (): void {
    $this->getJson($this->tenantUrl($this->tenant, 'auth/me'))->assertUnauthorized();

    $this->actingAsTenantUser($this->tenantAdmin($this->tenant))
        ->getJson($this->tenantUrl($this->tenant, 'auth/me'))
        ->assertOk()
        ->assertJsonPath('data.user.email', 'admin@acme.test');
});

it('logs out and ends the session', function (): void {
    $this->fromSpa($this->tenant)
        ->postJson($this->tenantUrl($this->tenant, 'auth/login'), ['email' => 'admin@acme.test', 'password' => 'password1'])
        ->assertOk();

    $this->fromSpa($this->tenant)->postJson($this->tenantUrl($this->tenant, 'auth/logout'))->assertNoContent();

    auth()->forgetGuards();

    $this->fromSpa($this->tenant)->getJson($this->tenantUrl($this->tenant, 'auth/me'))->assertUnauthorized();
});
