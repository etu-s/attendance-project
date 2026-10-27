<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Http\Requests\LeaveAdjustmentRequest;
use App\Modules\Leave\Http\Resources\LeavePresenter;
use App\Modules\Leave\Models\LeaveLedgerEntry;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Support\LeaveYears;
use App\Modules\Organization\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Balances and the ledger behind them. `?year=` is a leave year (default: the
 * current one).
 */
final class LeaveBalanceController extends Controller
{
    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly LeaveYears $years,
        private readonly TenantClock $clock,
    ) {}

    public function employee(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('leave.view');
        $this->authorize('view', $employee);

        return $this->respond($employee, $request);
    }

    /**
     * The signed-in employee's own balances; 404 when the login has no employee.
     */
    public function mine(Request $request): JsonResponse
    {
        $employee = Employee::query()->where('user_id', $request->user()?->getAuthIdentifier())->firstOrFail();

        return $this->respond($employee, $request);
    }

    public function ledger(Request $request, Employee $employee): JsonResponse
    {
        $this->authorize('leave.view');
        $this->authorize('view', $employee);
        $year = $this->year($request);

        return response()->json([
            'leave_year' => $year,
            'data' => $this->balances->ledger($employee, $year)->map(fn (LeaveLedgerEntry $e) => LeavePresenter::entry($e))->all(),
        ]);
    }

    public function adjust(LeaveAdjustmentRequest $request): JsonResponse
    {
        $employee = Employee::query()->findOrFail((int) $request->validated('employee_id'));
        $this->authorize('view', $employee);

        /** @var User $user */
        $user = $request->user();
        $type = LeaveType::query()->findOrFail((int) $request->validated('leave_type_id'));
        $year = (int) ($request->validated('leave_year') ?? $this->years->of($this->clock->today()));

        $entry = $this->balances->adjust($employee, $type, $year, $request->halves(), (string) $request->validated('reason'), $user);

        return response()->json(['data' => LeavePresenter::entry($entry)], 201);
    }

    private function respond(Employee $employee, Request $request): JsonResponse
    {
        $year = $this->year($request);

        return response()->json([
            'leave_year' => $year,
            'year_start' => $this->years->start($year)->format('Y-m-d'),
            'year_end' => $this->years->end($year)->format('Y-m-d'),
            'data' => LeavePresenter::balances($this->balances->forEmployee($employee, $year)),
        ]);
    }

    private function year(Request $request): int
    {
        $year = $request->integer('year');

        return $year >= 2000 && $year <= 2100 ? $year : $this->years->of($this->clock->today());
    }
}
