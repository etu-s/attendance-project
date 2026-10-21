<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Database\Factories;

use App\Modules\Attendance\Enums\PunchSourceKind;
use App\Modules\Attendance\Models\PunchSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Tenant context only. The key is unknown; when a test needs to authenticate,
 * create the source through PunchSourceService instead (it returns the key).
 *
 * @extends Factory<PunchSource>
 */
class PunchSourceFactory extends Factory
{
    protected $model = PunchSource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Terminal '.fake()->unique()->numberBetween(1, 9999),
            'kind' => PunchSourceKind::Device,
            'key_prefix' => Str::lower(Str::random(10)),
            'key_hash' => hash('sha256', Str::random(40)),
            'signing_secret' => null,
            'ip_allowlist' => null,
            'is_active' => true,
        ];
    }
}
