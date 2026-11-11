<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Controllers;

use App\Modules\Central\Http\Resources\SuperAdminResource;
use App\Modules\Central\Models\SuperAdmin;
use App\Modules\Central\Services\SuperAdminPasswordService;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\ForgotPasswordRequest;
use App\Modules\Core\Http\Requests\LoginRequest;
use App\Modules\Core\Http\Requests\SetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    /**
     * Always the same answer, whether or not the email has an account.
     */
    public function forgotPassword(ForgotPasswordRequest $request, SuperAdminPasswordService $passwords): JsonResponse
    {
        $passwords->sendReset((string) $request->validated('email'));

        return response()->json(['message' => 'If an account exists for this email, we sent a link to reset the password.'], 202);
    }

    public function resetPassword(SetPasswordRequest $request, SuperAdminPasswordService $passwords): Response
    {
        $passwords->reset($request->credentials());

        return response()->noContent();
    }

    public function login(LoginRequest $request): SuperAdminResource
    {
        $guard = Auth::guard('central');

        if (! $guard->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        /** @var SuperAdmin $admin */
        $admin = $guard->user();
        $admin->forceFill(['last_login_at' => now()])->save();

        return new SuperAdminResource($admin);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('central')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): SuperAdminResource
    {
        /** @var SuperAdmin $admin */
        $admin = $request->user();

        return new SuperAdminResource($admin);
    }
}
