<?php

declare(strict_types=1);

namespace App\Modules\Central\Models;

use App\Modules\Central\Database\Factories\SuperAdminFactory;
use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Contracts\AuditActor;
use App\Modules\Core\Enums\ActorType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Platform operator. Authenticates on the `central` guard only and has no
 * access to tenant routes.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $last_login_at
 */
class SuperAdmin extends Authenticatable implements AuditActor
{
    use Auditable, CentralConnection;

    /** @use HasFactory<SuperAdminFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /** @var list<string> */
    protected array $auditIgnore = ['last_login_at'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function auditActorType(): ActorType
    {
        return ActorType::SuperAdmin;
    }

    public function auditActorId(): int
    {
        return (int) $this->getKey();
    }

    public function auditActorName(): string
    {
        return $this->name;
    }

    protected static function newFactory(): SuperAdminFactory
    {
        return SuperAdminFactory::new();
    }
}
