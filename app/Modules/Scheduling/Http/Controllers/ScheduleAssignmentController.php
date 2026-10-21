<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Scheduling\Http\Requests\IndexScheduleAssignmentsRequest;
use App\Modules\Scheduling\Http\Requests\StoreScheduleAssignmentRequest;
use App\Modules\Scheduling\Http\Requests\UpdateScheduleAssignmentRequest;
use App\Modules\Scheduling\Http\Resources\ScheduleAssignmentResource;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Scheduling\Services\ScheduleAssignmentService;
use App\Modules\Scheduling\Services\ScheduleTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

final class ScheduleAssignmentController extends Controller
{
    public function __construct(
        private readonly ScheduleAssignmentService $assignments,
        private readonly ScheduleTargets $targets,
    ) {}

    public function index(IndexScheduleAssignmentsRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $page = $this->assignments->paginate($request->validated(), $request->sort(), $request->perPage(), $user);
        $this->withNames(collect($page->items()));

        return ScheduleAssignmentResource::collection($page);
    }

    public function store(StoreScheduleAssignmentRequest $request): JsonResponse
    {
        // Passed through as given: the service rejects an organization target with an id.
        $targetId = $request->validated('target_id');

        $assignment = $this->assignments->assign(
            $request->target(),
            $targetId === null ? null : (int) $targetId,
            (int) $request->validated('shift_id'),
            $request->effectiveFrom(),
            $request->localDate('effective_to'),
            $request->localDate('anchor_date'),
        );

        return $this->single($assignment)->response()->setStatusCode(201);
    }

    public function show(ScheduleAssignment $scheduleAssignment): ScheduleAssignmentResource
    {
        $this->authorize('schedules.view');

        return $this->single($scheduleAssignment->load('shift'));
    }

    public function update(UpdateScheduleAssignmentRequest $request, ScheduleAssignment $scheduleAssignment): ScheduleAssignmentResource
    {
        return $this->single($this->assignments->update($scheduleAssignment, $request->validated()));
    }

    public function destroy(ScheduleAssignment $scheduleAssignment): Response
    {
        $this->authorize('schedules.manage');
        $this->assignments->delete($scheduleAssignment);

        return response()->noContent();
    }

    private function single(ScheduleAssignment $assignment): ScheduleAssignmentResource
    {
        $this->withNames(collect([$assignment]));

        return new ScheduleAssignmentResource($assignment);
    }

    /**
     * @param  Collection<int, ScheduleAssignment>  $assignments
     */
    private function withNames(Collection $assignments): void
    {
        $names = $this->targets->names($assignments);
        $assignments->each(fn (ScheduleAssignment $a) => $a->setAttribute('target_name', $names[$a->targetKey()] ?? null));
    }
}
