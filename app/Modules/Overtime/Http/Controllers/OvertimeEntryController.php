<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Overtime\Http\Requests\OvertimeEntryRequest;
use App\Modules\Overtime\Http\Resources\OvertimeRecordResource;
use App\Modules\Overtime\Models\OvertimeRecord;
use App\Modules\Overtime\Services\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Manual overtime for work no punch captured (overtime.manage).
 */
final class OvertimeEntryController extends Controller
{
    public function __construct(
        private readonly OvertimeService $overtime,
    ) {}

    public function store(OvertimeEntryRequest $request): JsonResponse
    {
        $employee = Employee::query()->findOrFail((int) $request->validated('employee_id'));
        $this->authorize('view', $employee);

        /** @var User $user */
        $user = $request->user();
        /** @var array{work_date: string, minutes: int, kind: string, reason: string} $data */
        $data = $request->validated();

        return (new OvertimeRecordResource($this->overtime->createManual($employee, $data, $user)->load(['employee', 'decider', 'creator'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(OvertimeEntryRequest $request, OvertimeRecord $overtimeRecord): OvertimeRecordResource
    {
        $this->authorize('view', $overtimeRecord->employee);

        /** @var array{minutes?: int, kind?: string, reason?: string} $data */
        $data = $request->validated();

        return new OvertimeRecordResource($this->overtime->updateManual($overtimeRecord, $data)->load(['employee', 'decider', 'creator']));
    }

    public function destroy(OvertimeRecord $overtimeRecord): Response
    {
        $this->authorize('overtime.manage');
        $this->authorize('view', $overtimeRecord->employee);
        $this->overtime->deleteManual($overtimeRecord);

        return response()->noContent();
    }
}
