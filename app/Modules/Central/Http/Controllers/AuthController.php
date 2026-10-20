<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Controllers;

use App\Modules\Central\Http\Resources\SuperAdminResource;
use App\Modules\Central\Models\SuperAdmin;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
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
