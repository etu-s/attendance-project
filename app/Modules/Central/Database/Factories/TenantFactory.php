<?php

declare(strict_types=1);

namespace App\Modules\Central\Database\Factories;

use App\Modules\Central\Enums\TenantStatus;
use App\Modules\Central\Models\Plan;
use App\Modules\Central\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates the central tenant row only — no database file. Use
 * TenantProvisioner (or the `createTenant()` test helper) for a usable tenant.
 *
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'plan_id' => Plan::factory(),
            'status' => TenantStatus::Active,
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => TenantStatus::Suspended]);
    }

    public function withSubdomain(string $subdomain): static
    {
        return $this->afterCreating(fn (Tenant $tenant) => $tenant->domains()->create(['domain' => $subdomain]));
    }
}
