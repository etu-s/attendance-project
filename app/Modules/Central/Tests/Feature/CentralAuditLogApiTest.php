<?php

declare(strict_types=1);

use App\Modules\Central\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows super admins the central audit log, newest first, with filters', function (): void {
    $this->actingAsSuperAdmin();
    $this->postJson($this->centralUrl('plans'), ['name' => 'Gold', 'slug' => 'gold', 'max_employees' => 500])->assertCreated();
    $plan = Plan::query()->where('slug', 'gold')->firstOrFail();
    $this->putJson($this->centralUrl("plans/{$plan->id}"), ['name' => 'Gold plus'])->assertOk();

    $this->getJson($this->centralUrl('audit-logs?subject_type=plan'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'updated')
        ->assertJsonPath('data.1.action', 'created');
    $this->getJson($this->centralUrl('audit-logs?action=created&subject_type=plan&from='.now()->utc()->format('Y-m-d')))
        ->assertJsonCount(1, 'data');
    $this->getJson($this->centralUrl('audit-logs?action=exploded'))->assertJsonValidationErrors('action');
});

it('is for super admins only, and only on central hosts', function (): void {
    $this->getJson($this->centralUrl('audit-logs'))->assertUnauthorized();

    $tenant = $this->createTenant('acme');
    $this->actingAsSuperAdmin()->getJson($this->tenantUrl($tenant, 'central/audit-logs'))->assertNotFound();
});
