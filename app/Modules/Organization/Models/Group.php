<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Organization\Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A cross-department set of employees (e.g. "Night crew"). When an employee
 * is in several groups, the one with the lowest `priority` wins for schedules.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property int $priority
 * @property string|null $description
 * @property bool $is_active
 */
class Group extends Model
{
    use Auditable;

    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'priority', 'description', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<GroupMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMembership::class);
    }

    /**
     * @return list<int>
     */
    public function managerIds(): array
    {
        return DB::table('group_managers')
            ->where('group_id', $this->id)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected static function newFactory(): GroupFactory
    {
        return GroupFactory::new();
    }
}
