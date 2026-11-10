<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Http\Requests\LockPayrollPeriodRequest;
use App\Modules\Payroll\Http\Requests\UnlockPayrollPeriodRequest;
use App\Modules\Payroll\Http\Resources\PayrollPeriodResource;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class PayrollPeriodController extends Controller
{
    public function __construct(
        private readonly PayrollPeriodService $periods,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('payroll-periods.view');
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(PayrollPeriodStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('ams.pagination.max_per_page')],
        ]);

        return PayrollPeriodResource::collection($this->periods->paginate(
            isset($validated['status']) ? (string) $validated['status'] : null,
            (int) ($validated['per_page'] ?? config('ams.pagination.default_per_page')),
        ));
    }

    public function store(LockPayrollPeriodRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string, start_date: string, end_date: string} $data */
        $data = $request->validated();

        return (new PayrollPeriodResource($this->periods->lock($data, $user)))->response()->setStatusCode(201);
    }

    public function lock(Request $request, PayrollPeriod $payrollPeriod): PayrollPeriodResource
    {
        $this->authorize('payroll-periods.manage');
        /** @var User $user */
        $user = $request->user();

        return new PayrollPeriodResource($this->periods->relock($payrollPeriod, $user));
    }

    public function unlock(UnlockPayrollPeriodRequest $request, PayrollPeriod $payrollPeriod): PayrollPeriodResource
    {
        /** @var User $user */
        $user = $request->user();

        return new PayrollPeriodResource($this->periods->unlock($payrollPeriod, $user, (string) $request->validated('reason')));
    }
}
