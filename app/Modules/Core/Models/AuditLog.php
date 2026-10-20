<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\AuditLogFactory;
use App\Modules\Core\Enums\ActorType;
use App\Modules\Core\Enums\AuditAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only record of a change to a sensitive entity. There is no update or
 * delete path in the application; rows are written by AuditLogger only.
 *
 * @property int $id
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property AuditAction $action
 * @property string $auditable_type
 * @property string $auditable_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'actor_id' => 'integer',
            'action' => AuditAction::class,
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AuditLogFactory
    {
        return AuditLogFactory::new();
    }
}
