<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Organization\Http\Resources\EmployeeResource;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\EmployeeService;
use Illuminate\Http\Request;

/**
 * The signed-in user's own employee record (self-service; no permission
 * needed beyond being signed in). 404 when the login is not linked.
 */
final class MyEmployeeController extends Controller
{
    public function __invoke(Request $request, EmployeeService $employees): EmployeeResource
    {
        $employee = Employee::query()->where('user_id', $request->user()?->getAuthIdentifier())->firstOrFail();

        return new EmployeeResource($employees->load($employee));
    }
}
