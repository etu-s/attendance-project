<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Http\Requests\PunchIdentifierRequest;
use App\Modules\Attendance\Http\Resources\PunchIdentifierResource;
use App\Modules\Attendance\Models\EmployeePunchIdentifier;
use App\Modules\Attendance\Services\PunchIdentifierService;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Badge / card numbers per employee (punches.manage, plus seeing the employee).
 */
final class PunchIdentifierController extends Controller
{
    public function __construct(
        private readonly PunchIdentifierService $identifiers,
    ) {}

    public function index(Employee $employee): AnonymousResourceCollection
    {
        $this->authorize('punches.view');
        $this->authorize('view', $employee);

        return PunchIdentifierResource::collection($this->identifiers->forEmployee($employee));
    }

    public function store(PunchIdentifierRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        /** @var User $user */
        $user = $request->user();
        $identifier = $this->identifiers->create($employee, (string) $request->validated('identifier'), $request->validated('label'), $user);

        return (new PunchIdentifierResource($identifier))->response()->setStatusCode(201);
    }

    public function destroy(EmployeePunchIdentifier $punchIdentifier): Response
    {
        $this->authorize('punches.manage');
        $this->authorize('view', Employee::withTrashed()->findOrFail($punchIdentifier->employee_id));
        $this->identifiers->delete($punchIdentifier);

        return response()->noContent();
    }
}
