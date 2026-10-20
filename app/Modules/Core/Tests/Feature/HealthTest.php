<?php

declare(strict_types=1);

use App\Modules\Central\Enums\TenantStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports a healthy tenant database', function (): void {
    $tenant = $this->createTenant('acme');

    $this->getJson($this->tenantUrl($tenant, 'health'))
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'database' => 'ok']);
});

it('answers 403 on a suspended tenant and 503 while provisioning', function (): void {
    $tenant = $this->createTenant('acme');

    $tenant->update(['status' => TenantStatus::Suspended]);
    $this->getJson($this->tenantUrl($tenant, 'health'))->assertForbidden();

    $tenant->update(['status' => TenantStatus::Provisioning]);
    $this->getJson($this->tenantUrl($tenant, 'health'))->assertServiceUnavailable();
});
