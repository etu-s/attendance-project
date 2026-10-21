<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Organization\Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A site or branch. Its timezone (when set) overrides the tenant timezone for
 * employees assigned here, e.g. when deciding which day a punch belongs to.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $address
 * @property string|null $timezone
 * @property bool $is_active
 */
class Location extends Model
{
    use Auditable;

    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'address', 'timezone', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<EmployeeAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }
}
