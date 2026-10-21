<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Scheduling\Http\Requests\HolidayRequest;
use App\Modules\Scheduling\Http\Requests\IndexHolidaysRequest;
use App\Modules\Scheduling\Http\Resources\HolidayResource;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Services\HolidayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class HolidayController extends Controller
{
    public function __construct(
        private readonly HolidayService $holidays,
    ) {}

    /**
     * Holidays occurring in `year` (recurring ones included), by date.
     */
    public function index(IndexHolidaysRequest $request): AnonymousResourceCollection
    {
        $location = $request->validated('location_id');

        return HolidayResource::collection($this->holidays->forYear(
            (int) $request->validated('year'),
            $location === null ? null : (int) $location,
        ));
    }

    public function store(HolidayRequest $request): JsonResponse
    {
        return (new HolidayResource($this->holidays->create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(Holiday $holiday): HolidayResource
    {
        $this->authorize('holidays.view');

        return new HolidayResource($holiday->load('location'));
    }

    public function update(HolidayRequest $request, Holiday $holiday): HolidayResource
    {
        return new HolidayResource($this->holidays->update($holiday, $request->validated()));
    }

    public function destroy(Holiday $holiday): Response
    {
        $this->authorize('holidays.manage');
        $this->holidays->delete($holiday);

        return response()->noContent();
    }
}
