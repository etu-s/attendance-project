<?php

declare(strict_types=1);

use App\Modules\Leave\Http\Controllers\LeaveBalanceController;
use App\Modules\Leave\Http\Controllers\LeaveCalendarController;
use App\Modules\Leave\Http\Controllers\LeaveRequestController;
use App\Modules\Leave\Http\Controllers\LeaveTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('leave-types', [LeaveTypeController::class, 'index'])->name('leave-types.index');
    Route::post('leave-types', [LeaveTypeController::class, 'store'])->name('leave-types.store');
    Route::put('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->name('leave-types.update');
    Route::delete('leave-types/{leaveType}', [LeaveTypeController::class, 'destroy'])->name('leave-types.destroy');

    Route::get('leave-requests', [LeaveRequestController::class, 'index'])->name('leave-requests.index');
    Route::post('leave-requests/preview', [LeaveRequestController::class, 'preview'])->name('leave-requests.preview');
    Route::post('leave-requests', [LeaveRequestController::class, 'store'])->name('leave-requests.store');
    Route::get('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])->whereNumber('leaveRequest')->name('leave-requests.show');
    Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
    Route::post('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])->name('leave-requests.reject');
    Route::post('leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');

    Route::get('leave-calendar', LeaveCalendarController::class)->name('leave-calendar');

    Route::get('employees/{employee}/leave-balances', [LeaveBalanceController::class, 'employee'])->name('employees.leave-balances');
    Route::get('employees/{employee}/leave-ledger', [LeaveBalanceController::class, 'ledger'])->name('employees.leave-ledger');
    Route::get('me/leave-balances', [LeaveBalanceController::class, 'mine'])->name('me.leave-balances');
    Route::post('leave-adjustments', [LeaveBalanceController::class, 'adjust'])->name('leave-adjustments.store');
});
