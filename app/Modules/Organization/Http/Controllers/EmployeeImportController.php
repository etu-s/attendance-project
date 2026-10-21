<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Http\Requests\ImportEmployeesRequest;
use App\Modules\Organization\Http\Resources\EmployeeImportResource;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\EmployeeImport;
use App\Modules\Organization\Services\EmployeeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class EmployeeImportController extends Controller
{
    /**
     * 202: the file is stored and processed on the queue; poll the import.
     */
    public function store(ImportEmployeesRequest $request, EmployeeImportService $imports): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $import = $imports->start($request->file('file'), $user);

        return (new EmployeeImportResource($import->refresh()))->response()->setStatusCode(202);
    }

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('import', Employee::class);

        return EmployeeImportResource::collection(
            EmployeeImport::query()->latest('id')->paginate((int) config('ams.pagination.default_per_page')),
        );
    }

    public function show(EmployeeImport $employeeImport): EmployeeImportResource
    {
        $this->authorize('import', Employee::class);

        return new EmployeeImportResource($employeeImport);
    }
}
