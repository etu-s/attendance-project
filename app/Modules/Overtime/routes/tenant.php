<?php

declare(strict_types=1);

use App\Modules\Overtime\Http\Controllers\OvertimeController;
use App\Modules\Overtime\Http\Controllers\OvertimeEntryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('overtime', [OvertimeController::class, 'index'])->name('overtime.index');
    Route::get('overtime/summary', [OvertimeController::class, 'summary'])->name('overtime.summary');
    Route::post('overtime/approve-batch', [OvertimeController::class, 'approveBatch'])->name('overtime.approve-batch');
    Route::post('overtime/recalculate', [OvertimeController::class, 'recalculate'])->name('overtime.recalculate');
    Route::post('overtime/{overtimeRecord}/approve', [OvertimeController::class, 'approve'])->whereNumber('overtimeRecord')->name('overtime.approve');
    Route::post('overtime/{overtimeRecord}/reject', [OvertimeController::class, 'reject'])->whereNumber('overtimeRecord')->name('overtime.reject');

    Route::post('overtime-entries', [OvertimeEntryController::class, 'store'])->name('overtime-entries.store');
    Route::put('overtime-entries/{overtimeRecord}', [OvertimeEntryController::class, 'update'])->name('overtime-entries.update');
    Route::delete('overtime-entries/{overtimeRecord}', [OvertimeEntryController::class, 'destroy'])->name('overtime-entries.destroy');

    Route::get('employees/{employee}/overtime', [OvertimeController::class, 'employee'])->name('employees.overtime');
    Route::get('me/overtime', [OvertimeController::class, 'mine'])->name('me.overtime');
});
