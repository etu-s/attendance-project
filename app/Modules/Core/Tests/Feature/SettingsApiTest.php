<?php

declare(strict_types=1);

use App\Modules\Core\Enums\AuditAction;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = $this->createTenant('acme', ['timezone' => 'Asia/Karachi']);
    $this->admin = $this->tenantAdmin($this->tenant);
});

it('lists every registered setting with its current value', function (): void {
    $response = $this->actingAsTenantUser($this->admin)
        ->getJson($this->tenantUrl($this->tenant, 'settings'))
        ->assertOk();

    // Other modules add their own groups; the general group is Core's.
    expect(collect($response->json('data'))->where('group', 'general')->pluck('key')->sort()->values()->all())
        ->toBe(['general.date_format', 'general.time_format', 'general.timezone', 'general.week_start'])
        ->and(collect($response->json('data'))->pluck('key')->all())
        ->toEqual(array_values(array_unique(collect($response->json('data'))->pluck('key')->all())));

    $response
        ->assertJsonFragment(['key' => 'general.timezone', 'value' => 'Asia/Karachi', 'type' => 'string', 'group' => 'general'])
        ->assertJsonFragment(['key' => 'general.week_start', 'value' => 1, 'options' => [0, 1, 2, 3, 4, 5, 6]]);
});

it('lets HR managers view but not change settings', function (): void {
    $hr = $this->createTenantUser($this->tenant, RoleName::HrManager);

    $this->actingAsTenantUser($hr)->getJson($this->tenantUrl($this->tenant, 'settings'))->assertOk();
    $this->actingAsTenantUser($hr)
        ->putJson($this->tenantUrl($this->tenant, 'settings'), ['settings' => [['key' => 'general.week_start', 'value' => 0]]])
        ->assertForbidden();
});

it('hides settings from employees and guests', function (): void {
    $employee = $this->createTenantUser($this->tenant, RoleName::Employee);

    $this->actingAsTenantUser($employee)->getJson($this->tenantUrl($this->tenant, 'settings'))->assertForbidden();

    auth()->forgetGuards();
    $this->getJson($this->tenantUrl($this->tenant, 'settings'))->assertUnauthorized();
});

it('updates settings, casts values and audits the change', function (): void {
    $this->actingAsTenantUser($this->admin)
        ->putJson($this->tenantUrl($this->tenant, 'settings'), ['settings' => [
            ['key' => 'general.timezone', 'value' => 'Europe/Berlin'],
            ['key' => 'general.week_start', 'value' => '0'],
        ]])
        ->assertOk()
        ->assertJsonFragment(['key' => 'general.timezone', 'value' => 'Europe/Berlin'])
        ->assertJsonFragment(['key' => 'general.week_start', 'value' => 0]);

    $this->tenant->run(function (): void {
        expect(app(SettingsService::class)->get('general.week_start'))->toBe(0);

        // Provisioning already logged a system update of the timezone; count only this request's.
        $audit = AuditLog::query()
            ->where('auditable_type', 'setting')
            ->where('action', AuditAction::Updated)
            ->where('actor_type', 'user')
            ->get();
        expect($audit)->toHaveCount(2)
            ->and($audit->first()->actor_name)->toBe('Acme Admin');
    });
});

it('serves fresh values after an update (cache is invalidated)', function (): void {
    $this->tenant->run(fn () => app(SettingsService::class)->all());

    $this->actingAsTenantUser($this->admin)
        ->putJson($this->tenantUrl($this->tenant, 'settings'), ['settings' => [['key' => 'general.time_format', 'value' => 'h:i A']]])
        ->assertOk();

    $this->tenant->run(fn () => expect(app(SettingsService::class)->get('general.time_format'))->toBe('h:i A'));
});

it('validates values against each setting definition', function (array $payload, string $errorKey): void {
    $this->actingAsTenantUser($this->admin)
        ->putJson($this->tenantUrl($this->tenant, 'settings'), ['settings' => $payload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);
})->with([
    'invalid timezone' => [[['key' => 'general.timezone', 'value' => 'Mars/Olympus']], 'settings.0.value'],
    'value outside options' => [[['key' => 'general.date_format', 'value' => 'Y/m/d']], 'settings.0.value'],
    'wrong type' => [[['key' => 'general.week_start', 'value' => 'monday']], 'settings.0.value'],
    'unknown key' => [[['key' => 'general.unknown', 'value' => 'x']], 'settings.0.key'],
    'duplicate keys' => [[['key' => 'general.week_start', 'value' => 1], ['key' => 'general.week_start', 'value' => 2]], 'settings.0.key'],
    'empty list' => [[], 'settings'],
]);

it('keeps settings separate per tenant', function (): void {
    $globex = $this->createTenant('globex');

    $this->actingAsTenantUser($this->admin)
        ->putJson($this->tenantUrl($this->tenant, 'settings'), ['settings' => [['key' => 'general.week_start', 'value' => 6]]])
        ->assertOk();

    $globex->run(fn () => expect(app(SettingsService::class)->get('general.week_start'))->toBe(1));
});
