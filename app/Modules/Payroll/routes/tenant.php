<?php

declare(strict_types=1);

use App\Modules\Payroll\Http\Controllers\PayrollPeriodController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('payroll-periods', [PayrollPeriodController::class, 'index'])->name('payroll-periods.index');
    Route::post('payroll-periods', [PayrollPeriodController::class, 'store'])->name('payroll-periods.store');
    Route::post('payroll-periods/{payrollPeriod}/lock', [PayrollPeriodController::class, 'lock'])->whereNumber('payrollPeriod')->name('payroll-periods.lock');
    Route::post('payroll-periods/{payrollPeriod}/unlock', [PayrollPeriodController::class, 'unlock'])->whereNumber('payrollPeriod')->name('payroll-periods.unlock');
});
