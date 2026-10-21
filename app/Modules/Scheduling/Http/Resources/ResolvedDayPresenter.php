<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Support\ResolvedDay;
use App\Modules\Timetable\Support\ScheduledPeriod;

/**
 * JSON shape of a resolved day (used by the employee schedule, my schedule
 * and roster endpoints).
 */
final class ResolvedDayPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(ResolvedDay $day): array
    {
        return [
            'date' => $day->date,
            'status' => $day->status->value,
            'source' => $day->source === null ? null : [
                'type' => $day->source,
                'id' => $day->sourceId,
                'name' => $day->sourceName,
                'inherited_from' => $day->inheritedFrom,
            ],
            'shift' => $day->shift ? ['id' => $day->shift->id, 'name' => $day->shift->name] : null,
            'holiday' => $day->holiday,
            'timezone' => $day->timezone,
            'periods' => array_map(fn (ScheduledPeriod $p) => [
                'timetable' => [
                    'id' => $p->timetable->id,
                    'name' => $p->timetable->name,
                    'color' => $p->timetable->color,
                    'type' => $p->timetable->type->value,
                ],
                'starts_at' => $p->startsAt->toIso8601ZuluString(),
                'ends_at' => $p->endsAt->toIso8601ZuluString(),
                'minutes' => $p->minutes(),
            ], $day->periods),
        ];
    }

    /**
     * @param  array<string, ResolvedDay>  $days
     * @return list<array<string, mixed>>
     */
    public static function many(array $days): array
    {
        return array_values(array_map(self::present(...), $days));
    }
}
