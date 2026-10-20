<?php

declare(strict_types=1);

use App\Modules\Central\Http\Controllers\AuthController;
use App\Modules\Central\Http\Controllers\PlanController;
use App\Modules\Central\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

Route::middleware(['auth:central', 'throttle:api'])->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

    Route::apiResource('plans', PlanController::class);

    Route::apiResource('tenants', TenantController::class);
    Route::post('tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
    Route::post('tenants/{tenant}/activate', [TenantController::class, 'activate'])->name('tenants.activate');
});
