<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Http\Controllers;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Timetable\Http\Requests\IndexShiftsRequest;
use App\Modules\Timetable\Http\Requests\ShiftDaysRequest;
use App\Modules\Timetable\Http\Requests\ShiftPreviewRequest;
use App\Modules\Timetable\Http\Requests\ShiftRequest;
use App\Modules\Timetable\Http\Resources\ShiftResource;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Services\ShiftCalendar;
use App\Modules\Timetable\Services\ShiftService;
use App\Modules\Timetable\Support\ScheduledPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class ShiftController extends Controller
{
    public function __construct(
        private readonly ShiftService $shifts,
    ) {}

    public function index(IndexShiftsRequest $request): AnonymousResourceCollection
    {
        return ShiftResource::collection($this->shifts->paginate(
            $request->validated(),
            $request->search(),
            $request->sort(),
            $request->perPage(),
        ));
    }

    public function store(ShiftRequest $request): JsonResponse
    {
        return $this->detail($this->shifts->create($request->validated()))->response()->setStatusCode(201);
    }

    public function show(Shift $shift): ShiftResource
    {
        $this->authorize('shifts.view');

        return $this->detail($shift->load('days.timetable'));
    }

    public function update(ShiftRequest $request, Shift $shift): ShiftResource
    {
        return $this->detail($this->shifts->update($shift, $request->validated()));
    }

    public function destroy(Shift $shift): Response
    {
        $this->authorize('shifts.manage');
        $this->shifts->delete($shift);

        return response()->noContent();
    }

    public function duplicate(Shift $shift): JsonResponse
    {
        $this->authorize('shifts.manage');

        return $this->detail($this->shifts->duplicate($shift))->response()->setStatusCode(201);
    }

    public function days(ShiftDaysRequest $request, Shift $shift): ShiftResource
    {
        return $this->detail($this->shifts->setDays($shift, $request->days()));
    }

    /**
     * The cycle laid onto real dates: every date in range with its periods
     * (UTC instants, real minutes after DST).
     */
    public function preview(ShiftPreviewRequest $request, Shift $shift, ShiftCalendar $calendar, SettingsService $settings): JsonResponse
    {
        $from = $request->localDate('from');
        $anchor = $request->has('anchor') ? $request->localDate('anchor') : $from;
        $timezone = (string) ($request->validated('timezone') ?? $settings->get('general.timezone'));

        $days = $calendar->expand($shift, $anchor, $from, $request->localDate('to'), $timezone);

        return response()->json([
            'data' => array_map(fn (string $date, array $periods) => [
                'date' => $date,
                'day_index' => $calendar->cycleIndex($shift, $anchor, LocalDate::parse($date)),
                'periods' => array_map(fn (ScheduledPeriod $p) => [
                    'timetable' => ['id' => $p->timetable->id, 'name' => $p->timetable->name, 'code' => $p->timetable->code, 'color' => $p->timetable->color, 'type' => $p->timetable->type->value],
                    'starts_at' => $p->startsAt->toIso8601ZuluString(),
                    'ends_at' => $p->endsAt->toIso8601ZuluString(),
                    'minutes' => $p->minutes(),
                ], $periods),
            ], array_keys($days), array_values($days)),
            'meta' => ['timezone' => $timezone],
        ]);
    }

    private function detail(Shift $shift): ShiftResource
    {
        return (new ShiftResource($shift))->additional(['meta' => ['locked' => $this->shifts->isLocked($shift)]]);
    }
}
