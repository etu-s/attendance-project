<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Contracts\AuditActor;
use App\Modules\Core\Enums\ActorType;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Identity\Database\Factories\UserFactory;
use App\Modules\Identity\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A login account inside one tenant (lives in the tenant's database).
 * Each user holds exactly one role; employees (Module 2) will link to a
 * user through a nullable user_id.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserStatus $status
 * @property Carbon|null $last_login_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class User extends Authenticatable implements AuditActor
{
    use Auditable, HasApiTokens, HasRoles, SoftDeletes;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected string $guard_name = 'web';

    protected $fillable = ['name', 'email', 'password', 'status'];

    protected $hidden = ['password', 'remember_token'];

    /** @var list<string> */
    protected array $auditIgnore = ['last_login_at'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
        ];
    }

    public function roleName(): ?RoleName
    {
        $role = $this->roles->first()?->name;

        return $role === null ? null : RoleName::tryFrom($role);
    }

    public function isTenantAdmin(): bool
    {
        return $this->roleName() === RoleName::TenantAdmin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function auditActorType(): ActorType
    {
        return ActorType::User;
    }

    public function auditActorId(): int
    {
        return (int) $this->getKey();
    }

    public function auditActorName(): string
    {
        return $this->name;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
