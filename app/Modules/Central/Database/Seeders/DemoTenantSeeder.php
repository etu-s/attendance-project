<?php

declare(strict_types=1);

namespace App\Modules\Central\Database\Seeders;

use App\Modules\Attendance\Database\Seeders\DemoAttendanceSeeder;
use App\Modules\Central\Models\Domain;
use App\Modules\Central\Models\Plan;
use App\Modules\Central\Services\TenantProvisioner;
use App\Modules\Core\Tenancy\TenantProvisioningData;
use App\Modules\Organization\Database\Seeders\DemoOrganizationSeeder;
use App\Modules\Scheduling\Database\Seeders\DemoSchedulingSeeder;
use App\Modules\Timetable\Database\Seeders\DemoTimetableSeeder;
use Illuminate\Database\Seeder;

/**
 * Local-only demo tenant at http://acme.localhost:5173 with its Tenant Admin
 * and demo data (organization, employees, timetables, shifts, schedules and a
 * week of simulated punches).
 * Credentials come from config('ams.seed') (SEED_DEMO_ADMIN_* in .env) (defaults in .env.example).
 */
final class DemoTenantSeeder extends Seeder
{
    public function run(TenantProvisioner $provisioner): void
    {
        if (Domain::query()->where('domain', 'acme')->exists()) {
            return;
        }

        $tenant = $provisioner->provision(new TenantProvisioningData(
            name: 'Acme Corporation',
            subdomain: 'acme',
            planId: (int) Plan::query()->where('slug', 'business')->value('id'),
            timezone: 'Asia/Karachi',
            adminName: 'Acme Admin',
            adminEmail: (string) config('ams.seed.demo_admin_email'),
            adminPassword: (string) config('ams.seed.demo_admin_password'),
        ));

        $tenant->run(fn () => $this->call([DemoOrganizationSeeder::class, DemoTimetableSeeder::class, DemoSchedulingSeeder::class, DemoAttendanceSeeder::class]));
    }
}
