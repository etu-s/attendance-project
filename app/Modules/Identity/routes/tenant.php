<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

// Public: password links (rate limited like login).
Route::middleware('throttle:password')->group(function (): void {
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgot-password');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset-password');
    Route::post('auth/accept-invitation', [AuthController::class, 'acceptInvitation'])->name('auth.accept-invitation');
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

    Route::apiResource('users', UserController::class);
    Route::post('users/{user}/invitation', [UserController::class, 'invite'])
        ->middleware('throttle:heavy')->name('users.invitation');
});
