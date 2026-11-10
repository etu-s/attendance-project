<?php

declare(strict_types=1);

use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Http\Controllers\ReportController;
use App\Modules\Reports\Http\Controllers\ReportExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('reports/dashboard', [ReportController::class, 'dashboard'])->name('reports.dashboard');

    Route::get('reports/exports', [ReportExportController::class, 'index'])->name('reports.exports.index');
    Route::post('reports/exports', [ReportExportController::class, 'store'])->middleware('throttle:heavy')->name('reports.exports.store');
    Route::get('reports/exports/{reportExport}', [ReportExportController::class, 'show'])->whereNumber('reportExport')->name('reports.exports.show');
    Route::get('reports/exports/{reportExport}/download', [ReportExportController::class, 'download'])->whereNumber('reportExport')->name('reports.exports.download');

    Route::get('reports/{report}', [ReportController::class, 'show'])
        ->whereIn('report', array_map(fn (ReportKind $k) => $k->value, ReportKind::cases()))
        ->name('reports.show');
});
