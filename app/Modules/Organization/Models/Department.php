<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Organization\Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $code
 * @property bool $is_active
 */
class Department extends Model
{
    use Auditable;

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected $fillable = ['parent_id', 'name', 'code', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Department, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<EmployeeAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    /**
     * Manager user ids. Users belong to the Identity module, so only ids are
     * stored and read here; display data comes from UserService::summaries().
     *
     * @return list<int>
     */
    public function managerIds(): array
    {
        return DB::table('department_managers')
            ->where('department_id', $this->id)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }
}
