<?php

declare(strict_types=1);

use App\Modules\Central\Models\Plan;
use App\Modules\Central\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAsSuperAdmin();
});

it('lists plans with tenant counts, search, sort and pagination', function (): void {
    $gold = Plan::factory()->create(['name' => 'Gold']);
    Plan::factory()->create(['name' => 'Silver']);
    Plan::factory()->create(['name' => 'Bronze']);
    Tenant::factory()->count(2)->create(['plan_id' => $gold->id]);

    $this->getJson($this->centralUrl('plans?sort=-name&per_page=2'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Silver')
        ->assertJsonPath('meta.total', 3);

    $this->getJson($this->centralUrl('plans?search=gol'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.tenants_count', 2);
});

it('filters plans by active flag', function (): void {
    Plan::factory()->create();
    Plan::factory()->inactive()->create();

    $this->getJson($this->centralUrl('plans?is_active=0'))->assertOk()->assertJsonPath('meta.total', 1);
});

it('rejects unknown sort columns', function (): void {
    $this->getJson($this->centralUrl('plans?sort=password'))->assertUnprocessable()->assertJsonValidationErrors('sort');
});

it('creates a plan', function (): void {
    $this->postJson($this->centralUrl('plans'), [
        'name' => 'Pro',
        'slug' => 'pro',
        'max_employees' => 200,
        'features' => ['attendance', 'leave'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'pro')
        ->assertJsonPath('data.features', ['attendance', 'leave'])
        ->assertJsonPath('data.is_active', true);
});

it('allows an unlimited plan with null max_employees', function (): void {
    $this->postJson($this->centralUrl('plans'), ['name' => 'Unlimited', 'slug' => 'unlimited', 'max_employees' => null])
        ->assertCreated()
        ->assertJsonPath('data.max_employees', null);
});

it('validates plan input', function (array $payload, string $field): void {
    Plan::factory()->create(['slug' => 'taken']);

    $this->postJson($this->centralUrl('plans'), array_merge(['name' => 'X', 'slug' => 'ok', 'max_employees' => 10], $payload))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'duplicate slug' => [['slug' => 'taken'], 'slug'],
    'bad slug' => [['slug' => 'Not A Slug'], 'slug'],
    'zero employees' => [['max_employees' => 0], 'max_employees'],
    'missing name' => [['name' => ''], 'name'],
    'duplicate feature' => [['features' => ['a', 'a']], 'features.1'],
]);

it('shows and updates a plan', function (): void {
    $plan = Plan::factory()->create(['slug' => 'basic']);

    $this->getJson($this->centralUrl("plans/{$plan->id}"))->assertOk()->assertJsonPath('data.slug', 'basic');

    $this->putJson($this->centralUrl("plans/{$plan->id}"), ['name' => 'Basic+', 'slug' => 'basic', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.name', 'Basic+')
        ->assertJsonPath('data.is_active', false);
});

it('deletes an unused plan but refuses one assigned to tenants', function (): void {
    $unused = Plan::factory()->create();
    $used = Plan::factory()->create();
    Tenant::factory()->create(['plan_id' => $used->id]);

    $this->deleteJson($this->centralUrl("plans/{$unused->id}"))->assertNoContent();
    $this->deleteJson($this->centralUrl("plans/{$used->id}"))
        ->assertStatus(409)
        ->assertJsonPath('rule', 'plan_in_use');

    expect(Plan::query()->whereKey($used->id)->exists())->toBeTrue();
});

it('requires a super admin', function (): void {
    auth()->forgetGuards();
    $this->app['auth']->guard('central')->logout();

    $this->getJson($this->centralUrl('plans'))->assertUnauthorized();
});
