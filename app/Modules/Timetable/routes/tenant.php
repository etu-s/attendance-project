<?php

declare(strict_types=1);

use App\Modules\Timetable\Http\Controllers\ShiftController;
use App\Modules\Timetable\Http\Controllers\TimetableController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::apiResource('timetables', TimetableController::class);
    Route::post('timetables/{timetable}/duplicate', [TimetableController::class, 'duplicate'])->name('timetables.duplicate');

    Route::apiResource('shifts', ShiftController::class);
    Route::post('shifts/{shift}/duplicate', [ShiftController::class, 'duplicate'])->name('shifts.duplicate');
    Route::put('shifts/{shift}/days', [ShiftController::class, 'days'])->name('shifts.days');
    Route::get('shifts/{shift}/preview', [ShiftController::class, 'preview'])->name('shifts.preview');
});
