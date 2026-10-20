<?php

declare(strict_types=1);

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'general.timezone',
            'value' => json_encode('UTC'),
        ];
    }
}
