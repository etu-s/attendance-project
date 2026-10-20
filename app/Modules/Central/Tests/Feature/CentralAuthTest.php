<?php

declare(strict_types=1);

use App\Modules\Central\Models\SuperAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = SuperAdmin::factory()->create(['email' => 'ops@ams.test']);
});

it('logs a super admin in on the central domain', function (): void {
    $this->fromSpa()
        ->postJson($this->centralUrl('auth/login'), ['email' => 'OPS@ams.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'ops@ams.test')
        ->assertJsonPath('data.role', 'super-admin');

    expect($this->admin->fresh()->last_login_at)->not->toBeNull();
});

it('rejects bad credentials', function (): void {
    $this->fromSpa()
        ->postJson($this->centralUrl('auth/login'), ['email' => 'ops@ams.test', 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('validates the login payload', function (): void {
    $this->fromSpa()
        ->postJson($this->centralUrl('auth/login'), ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

it('rate limits login attempts', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->fromSpa()->postJson($this->centralUrl('auth/login'), ['email' => 'ops@ams.test', 'password' => 'wrong']);
    }

    $this->fromSpa()
        ->postJson($this->centralUrl('auth/login'), ['email' => 'ops@ams.test', 'password' => 'password'])
        ->assertTooManyRequests();
});

it('returns the current admin and 401 for guests', function (): void {
    $this->getJson($this->centralUrl('auth/me'))->assertUnauthorized();

    $this->actingAsSuperAdmin($this->admin)
        ->getJson($this->centralUrl('auth/me'))
        ->assertOk()
        ->assertJsonPath('data.id', $this->admin->id);
});

it('logs out', function (): void {
    $this->fromSpa()->postJson($this->centralUrl('auth/login'), ['email' => 'ops@ams.test', 'password' => 'password'])->assertOk();
    $this->fromSpa()->postJson($this->centralUrl('auth/logout'))->assertNoContent();

    auth()->forgetGuards();
    $this->fromSpa()->getJson($this->centralUrl('auth/me'))->assertUnauthorized();
});

it('does not let a super admin session reach tenant routes', function (): void {
    $tenant = $this->createTenant('acme');

    $this->actingAsSuperAdmin($this->admin)
        ->getJson($this->tenantUrl($tenant, 'users'))
        ->assertUnauthorized();
});
