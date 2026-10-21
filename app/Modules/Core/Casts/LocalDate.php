<?php

declare(strict_types=1);

namespace App\Modules\Core\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A calendar date with no time or timezone (hire dates, effective dates, work
 * dates). Stored as `Y-m-d` text so SQL comparisons and indexes order
 * correctly; Laravel's `date` cast would store `Y-m-d 00:00:00` instead.
 *
 * In PHP it is a CarbonImmutable at 00:00 UTC, used only for its date part.
 *
 * @implements CastsAttributes<CarbonImmutable|null, CarbonImmutable|DateTimeInterface|string|null>
 */
final class LocalDate implements CastsAttributes
{
    public const FORMAT = 'Y-m-d';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::parse((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(self::FORMAT);
        }

        return self::parse((string) $value)->format(self::FORMAT);
    }

    public static function parse(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!'.self::FORMAT, substr($value, 0, 10), 'UTC');

        if ($date === null || $date->format(self::FORMAT) !== substr($value, 0, 10)) {
            throw new InvalidArgumentException("Invalid date [{$value}], expected Y-m-d.");
        }

        return $date;
    }
}
