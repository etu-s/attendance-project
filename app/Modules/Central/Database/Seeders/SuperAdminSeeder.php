<?php

declare(strict_types=1);

namespace App\Modules\Central\Database\Seeders;

use App\Modules\Central\Models\SuperAdmin;
use Illuminate\Database\Seeder;

/**
 * Local/dev super admin. Credentials come from config('ams.seed') (SEED_SUPER_ADMIN_* in .env)
 * (defaults are listed in .env.example). Never run against production.
 */
final class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('ams.seed.super_admin_email');

        if (SuperAdmin::query()->where('email', $email)->exists()) {
            return;
        }

        SuperAdmin::query()->create([
            'name' => 'Platform Admin',
            'email' => $email,
            'password' => (string) config('ams.seed.super_admin_password'),
        ]);
    }
}
