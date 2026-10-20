<?php

declare(strict_types=1);

namespace App\Modules\Central\Models;

use App\Modules\Central\Database\Factories\PlanFactory;
use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Subscription plan. Limits are recorded here; enforcement belongs to the
 * module that owns the limited resource (e.g. max_employees in Employees).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $max_employees null = unlimited
 * @property list<string> $features
 * @property bool $is_active
 */
class Plan extends Model
{
    use Auditable, CentralConnection;

    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'max_employees', 'features', 'is_active'];

    /** Mirrors the column default so a freshly created model reports it too. */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'max_employees' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Tenant, $this>
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }
}
