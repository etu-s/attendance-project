<?php

declare(strict_types=1);

use App\Modules\Core\Http\Controllers\AuditLogController;
use App\Modules\Core\Http\Controllers\HealthController;
use App\Modules\Core\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::get('health', HealthController::class)->name('health');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
});
