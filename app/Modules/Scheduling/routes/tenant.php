<?php

declare(strict_types=1);

use App\Modules\Scheduling\Http\Controllers\EmployeeScheduleController;
use App\Modules\Scheduling\Http\Controllers\HolidayController;
use App\Modules\Scheduling\Http\Controllers\RosterController;
use App\Modules\Scheduling\Http\Controllers\ScheduleAssignmentController;
use App\Modules\Scheduling\Http\Controllers\TemporaryScheduleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::apiResource('schedule-assignments', ScheduleAssignmentController::class);

    Route::get('temporary-schedules', [TemporaryScheduleController::class, 'all'])->name('temporary-schedules.all');
    Route::get('employees/{employee}/temporary-schedules', [TemporaryScheduleController::class, 'index'])->name('temporary-schedules.index');
    Route::post('employees/{employee}/temporary-schedules', [TemporaryScheduleController::class, 'store'])->name('temporary-schedules.store');
    Route::put('temporary-schedules/{temporarySchedule}', [TemporaryScheduleController::class, 'update'])->name('temporary-schedules.update');
    Route::delete('temporary-schedules/{temporarySchedule}', [TemporaryScheduleController::class, 'destroy'])->name('temporary-schedules.destroy');

    Route::get('employees/{employee}/schedule', [EmployeeScheduleController::class, 'show'])->name('employees.schedule');
    Route::get('me/schedule', [EmployeeScheduleController::class, 'mine'])->name('me.schedule');
    Route::get('roster', RosterController::class)->name('roster');

    Route::apiResource('holidays', HolidayController::class);
});
