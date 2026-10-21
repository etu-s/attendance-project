<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Models;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Concerns\Auditable;
use App\Modules\Organization\Models\Location;
use App\Modules\Scheduling\Database\Factories\HolidayFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property CarbonImmutable $date
 * @property string $name
 * @property int|null $location_id
 * @property bool $recurs_yearly
 * @property-read Location|null $location
 */
class Holiday extends Model
{
    use Auditable;

    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    protected $fillable = ['date', 'name', 'location_id', 'recurs_yearly'];

    protected $attributes = ['recurs_yearly' => false];

    protected function casts(): array
    {
        return ['date' => LocalDate::class, 'location_id' => 'integer', 'recurs_yearly' => 'boolean'];
    }

    /**
     * The date this holiday falls on in $year, or null (not yet started, or
     * a recurring 29 February in a non-leap year).
     */
    public function occurrenceIn(int $year): ?CarbonImmutable
    {
        if ($year === $this->date->year) {
            return $this->date;
        }

        if (! $this->recurs_yearly || $year < $this->date->year || ! checkdate($this->date->month, $this->date->day, $year)) {
            return null;
        }

        return LocalDate::parse(sprintf('%04d-%02d-%02d', $year, $this->date->month, $this->date->day));
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    protected static function newFactory(): HolidayFactory
    {
        return HolidayFactory::new();
    }
}
