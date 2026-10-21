<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Timetable\Database\Factories\ShiftFactory;
use App\Modules\Timetable\Enums\CycleUnit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property CycleUnit $cycle_unit
 * @property int $cycle_length
 * @property string|null $description
 * @property bool $is_active
 * @property-read Collection<int, ShiftDay> $days
 */
class Shift extends Model
{
    use Auditable;

    /** @use HasFactory<ShiftFactory> */
    use HasFactory;

    public const MAX_TIMETABLES_PER_DAY = 3;

    protected $fillable = ['name', 'code', 'cycle_unit', 'cycle_length', 'description', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'cycle_unit' => CycleUnit::class,
            'cycle_length' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function cycleDays(): int
    {
        return $this->cycle_unit->days($this->cycle_length);
    }

    /**
     * @return HasMany<ShiftDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(ShiftDay::class)->orderBy('day_index')->orderBy('id');
    }

    protected static function newFactory(): ShiftFactory
    {
        return ShiftFactory::new();
    }
}
