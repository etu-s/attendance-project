<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Core\Events\TenantDefaultsSyncRequested;
use Illuminate\Database\Seeder;

/**
 * Run per tenant by `php artisan tenants:seed`. Re-applies every module's
 * defaults (roles/permissions, missing settings). Safe to run after each
 * deploy that adds permissions or settings; it never overwrites tenant data.
 */
class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        event(new TenantDefaultsSyncRequested(tenant()));
    }
}
