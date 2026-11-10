<?php

declare(strict_types=1);

use App\Modules\Organization\Http\Controllers\DepartmentController;
use App\Modules\Organization\Http\Controllers\EmployeeController;
use App\Modules\Organization\Http\Controllers\EmployeeImportController;
use App\Modules\Organization\Http\Controllers\GroupController;
use App\Modules\Organization\Http\Controllers\LocationController;
use App\Modules\Organization\Http\Controllers\MyEmployeeController;
use App\Modules\Organization\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('organization', [OrganizationController::class, 'show'])->name('organization.show');
    Route::put('organization', [OrganizationController::class, 'update'])->name('organization.update');

    Route::apiResource('locations', LocationController::class);

    Route::apiResource('departments', DepartmentController::class);
    Route::put('departments/{department}/managers', [DepartmentController::class, 'managers'])->name('departments.managers');

    Route::apiResource('groups', GroupController::class);
    Route::put('groups/{group}/managers', [GroupController::class, 'managers'])->name('groups.managers');

    Route::get('me/employee', MyEmployeeController::class)->name('me.employee');

    // Registered before the resource so "import" is never read as an {employee} id.
    Route::post('employees/import', [EmployeeImportController::class, 'store'])->middleware('throttle:heavy')->name('employees.import');
    Route::get('employee-imports', [EmployeeImportController::class, 'index'])->name('employee-imports.index');
    Route::get('employee-imports/{employeeImport}', [EmployeeImportController::class, 'show'])->name('employee-imports.show');

    Route::apiResource('employees', EmployeeController::class);
    Route::prefix('employees/{employee}')->name('employees.')->controller(EmployeeController::class)->group(function (): void {
        Route::get('history', 'history')->name('history');
        Route::post('transfer', 'transfer')->name('transfer');
        Route::post('terminate', 'terminate')->name('terminate');
        Route::post('reinstate', 'reinstate')->name('reinstate');
        Route::post('groups', 'joinGroup')->name('groups.join');
        Route::post('groups/{group}/end', 'leaveGroup')->name('groups.leave');
        Route::put('user', 'linkUser')->name('user');
    });
});
