<?php

declare(strict_types=1);

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Enums\ActorType;
use App\Modules\Core\Enums\AuditAction;
use App\Modules\Core\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_type' => ActorType::User,
            'actor_id' => fake()->numberBetween(1, 50),
            'actor_name' => fake()->name(),
            'action' => AuditAction::Updated,
            'auditable_type' => 'user',
            'auditable_id' => (string) fake()->numberBetween(1, 50),
            'before' => ['name' => fake()->name()],
            'after' => ['name' => fake()->name()],
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Pest',
            'created_at' => now(),
        ];
    }
}
