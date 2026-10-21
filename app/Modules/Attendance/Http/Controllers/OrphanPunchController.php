<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Http\Requests\IndexOrphanPunchesRequest;
use App\Modules\Attendance\Http\Requests\LinkOrphanPunchRequest;
use App\Modules\Attendance\Http\Resources\OrphanPunchResource;
use App\Modules\Attendance\Models\OrphanPunch;
use App\Modules\Attendance\Services\OrphanPunchService;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrphanPunchController extends Controller
{
    public function __construct(
        private readonly OrphanPunchService $orphans,
    ) {}

    public function index(IndexOrphanPunchesRequest $request): AnonymousResourceCollection
    {
        return OrphanPunchResource::collection(
            $this->orphans->paginate($request->validated(), $request->search(), $request->sort(), $request->perPage()),
        );
    }

    /**
     * Links every open punch with this identifier to the employee.
     */
    public function link(LinkOrphanPunchRequest $request, OrphanPunch $orphanPunch): JsonResponse
    {
        $employee = Employee::query()->findOrFail((int) $request->validated('employee_id'));
        $this->authorize('view', $employee);

        /** @var User $user */
        $user = $request->user();
        $linked = $this->orphans->link($orphanPunch, $employee, $user);

        return response()->json(['linked' => $linked]);
    }

    public function dismiss(Request $request, OrphanPunch $orphanPunch): OrphanPunchResource
    {
        $this->authorize('punches.manage');

        /** @var User $user */
        $user = $request->user();

        return new OrphanPunchResource($this->orphans->dismiss($orphanPunch, $user)->load(['rawPunch.source', 'employee']));
    }
}
