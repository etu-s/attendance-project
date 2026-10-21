<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Timetable\Http\Requests\IndexTimetablesRequest;
use App\Modules\Timetable\Http\Requests\TimetableRequest;
use App\Modules\Timetable\Http\Resources\TimetableResource;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Services\TimetableRules;
use App\Modules\Timetable\Services\TimetableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class TimetableController extends Controller
{
    public function __construct(
        private readonly TimetableService $timetables,
        private readonly TimetableRules $rules,
    ) {}

    public function index(IndexTimetablesRequest $request): AnonymousResourceCollection
    {
        return TimetableResource::collection($this->timetables->paginate(
            $request->validated(),
            $request->search(),
            $request->sort(),
            $request->perPage(),
        ));
    }

    public function store(TimetableRequest $request): JsonResponse
    {
        return $this->detail($this->timetables->create($request->validated()))->response()->setStatusCode(201);
    }

    public function show(Timetable $timetable): TimetableResource
    {
        $this->authorize('timetables.view');

        return $this->detail($timetable->load('breaks'));
    }

    public function update(TimetableRequest $request, Timetable $timetable): TimetableResource
    {
        return $this->detail($this->timetables->update($timetable, $request->validated()));
    }

    public function destroy(Timetable $timetable): Response
    {
        $this->authorize('timetables.manage');
        $this->timetables->delete($timetable);

        return response()->noContent();
    }

    public function duplicate(Timetable $timetable): JsonResponse
    {
        $this->authorize('timetables.manage');

        return $this->detail($this->timetables->duplicate($timetable))->response()->setStatusCode(201);
    }

    /**
     * Single timetable plus `meta.locked` (used by a schedule, times read-only)
     * and `meta.effective` (grace and windows after workspace defaults).
     */
    private function detail(Timetable $timetable): TimetableResource
    {
        return (new TimetableResource($timetable))->additional(['meta' => [
            'locked' => $this->timetables->isLocked($timetable),
            'effective' => $this->rules->for($timetable),
        ]]);
    }
}
