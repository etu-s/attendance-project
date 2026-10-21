<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Http\Requests\DecideCorrectionRequest;
use App\Modules\Attendance\Http\Requests\IndexCorrectionsRequest;
use App\Modules\Attendance\Http\Requests\StoreCorrectionRequest;
use App\Modules\Attendance\Http\Resources\PunchCorrectionResource;
use App\Modules\Attendance\Models\PunchCorrection;
use App\Modules\Attendance\Services\PunchCorrectionService;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Every action also requires that the caller may see the employee
 * (EmployeePolicy, i.e. EmployeeVisibility).
 */
final class PunchCorrectionController extends Controller
{
    public function __construct(
        private readonly PunchCorrectionService $corrections,
    ) {}

    public function index(IndexCorrectionsRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return PunchCorrectionResource::collection(
            $this->corrections->paginate($request->validated(), $request->search(), $request->sort(), $request->perPage(), $user),
        );
    }

    public function store(StoreCorrectionRequest $request): JsonResponse
    {
        $employee = Employee::query()->findOrFail((int) $request->validated('employee_id'));
        $this->authorize('view', $employee);

        /** @var User $user */
        $user = $request->user();
        /** @var array{work_date: string, kind: string, punched_at?: string|null, raw_punch_id?: int|null, reason: string} $data */
        $data = $request->validated();

        return (new PunchCorrectionResource($this->corrections->create($employee, $data, $user)))->response()->setStatusCode(201);
    }

    public function approve(DecideCorrectionRequest $request, PunchCorrection $punchCorrection): PunchCorrectionResource
    {
        $this->authorize('view', $punchCorrection->employee);

        /** @var User $user */
        $user = $request->user();

        return new PunchCorrectionResource($this->corrections->approve($punchCorrection, $user, $request->validated('note')));
    }

    public function reject(DecideCorrectionRequest $request, PunchCorrection $punchCorrection): PunchCorrectionResource
    {
        $this->authorize('view', $punchCorrection->employee);

        /** @var User $user */
        $user = $request->user();

        return new PunchCorrectionResource($this->corrections->reject($punchCorrection, $user, $request->validated('note')));
    }
}
