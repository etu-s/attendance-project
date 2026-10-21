<?php

declare(strict_types=1);

use App\Modules\Attendance\Http\Controllers\AttendanceController;
use App\Modules\Attendance\Http\Controllers\OrphanPunchController;
use App\Modules\Attendance\Http\Controllers\PunchCorrectionController;
use App\Modules\Attendance\Http\Controllers\PunchIdentifierController;
use App\Modules\Attendance\Http\Controllers\PunchIngestionController;
use App\Modules\Attendance\Http\Controllers\PunchSourceController;
use App\Modules\Attendance\Http\Controllers\RawPunchController;
use App\Modules\Attendance\Http\Middleware\AuthenticatePunchSource;
use Illuminate\Support\Facades\Route;

// Ingestion: devices and integrations, authenticated by punch source key.
// Throttled before authentication, so guessing keys is rate limited too.
Route::middleware(['throttle:punches', AuthenticatePunchSource::class])->group(function (): void {
    Route::post('punches', [PunchIngestionController::class, 'store'])->name('punches.store');
    Route::post('punches/batch', [PunchIngestionController::class, 'batch'])->name('punches.batch');
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('punch-sources', [PunchSourceController::class, 'index'])->name('punch-sources.index');
    Route::post('punch-sources', [PunchSourceController::class, 'store'])->name('punch-sources.store');
    Route::get('punch-sources/{punchSource}', [PunchSourceController::class, 'show'])->name('punch-sources.show');
    Route::put('punch-sources/{punchSource}', [PunchSourceController::class, 'update'])->name('punch-sources.update');
    Route::delete('punch-sources/{punchSource}', [PunchSourceController::class, 'destroy'])->name('punch-sources.destroy');
    Route::post('punch-sources/{punchSource}/rotate-key', [PunchSourceController::class, 'rotate'])->name('punch-sources.rotate');

    Route::get('raw-punches', [RawPunchController::class, 'index'])->name('raw-punches.index');

    Route::get('orphan-punches', [OrphanPunchController::class, 'index'])->name('orphan-punches.index');
    Route::post('orphan-punches/{orphanPunch}/link', [OrphanPunchController::class, 'link'])->name('orphan-punches.link');
    Route::post('orphan-punches/{orphanPunch}/dismiss', [OrphanPunchController::class, 'dismiss'])->name('orphan-punches.dismiss');

    Route::get('employees/{employee}/punch-identifiers', [PunchIdentifierController::class, 'index'])->name('punch-identifiers.index');
    Route::post('employees/{employee}/punch-identifiers', [PunchIdentifierController::class, 'store'])->name('punch-identifiers.store');
    Route::delete('punch-identifiers/{punchIdentifier}', [PunchIdentifierController::class, 'destroy'])->name('punch-identifiers.destroy');

    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/summary', [AttendanceController::class, 'summary'])->name('attendance.summary');
    Route::post('attendance/recalculate', [AttendanceController::class, 'recalculate'])->name('attendance.recalculate');
    Route::get('attendance/{attendanceDay}', [AttendanceController::class, 'show'])->whereNumber('attendanceDay')->name('attendance.show');
    Route::get('employees/{employee}/attendance', [AttendanceController::class, 'employee'])->name('employees.attendance');
    Route::get('me/attendance', [AttendanceController::class, 'mine'])->name('me.attendance');

    Route::get('punch-corrections', [PunchCorrectionController::class, 'index'])->name('punch-corrections.index');
    Route::post('punch-corrections', [PunchCorrectionController::class, 'store'])->name('punch-corrections.store');
    Route::post('punch-corrections/{punchCorrection}/approve', [PunchCorrectionController::class, 'approve'])->name('punch-corrections.approve');
    Route::post('punch-corrections/{punchCorrection}/reject', [PunchCorrectionController::class, 'reject'])->name('punch-corrections.reject');
});
