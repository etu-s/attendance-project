<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\ForgotPasswordRequest;
use App\Modules\Core\Http\Requests\LoginRequest;
use App\Modules\Core\Http\Requests\SetPasswordRequest;
use App\Modules\Identity\Http\Resources\AuthenticatedUserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PasswordLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function login(LoginRequest $request): AuthenticatedUserResource
    {
        $guard = Auth::guard('web');

        if (! $guard->attempt($request->only('email', 'password'), $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        /** @var User $user */
        $user = $guard->user();

        // Checked after the password so account state is only revealed to its owner.
        if (! $user->isActive()) {
            $guard->logout();

            throw ValidationException::withMessages(['email' => 'This account is deactivated. Contact your administrator.']);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return new AuthenticatedUserResource($user->load('roles'));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * Always the same answer, whether or not the email has an account.
     */
    public function forgotPassword(ForgotPasswordRequest $request, PasswordLinkService $links): JsonResponse
    {
        $links->sendReset((string) $request->validated('email'));

        return response()->json(['message' => 'If an account exists for this email, we sent a link to reset the password.'], 202);
    }

    public function resetPassword(SetPasswordRequest $request, PasswordLinkService $links): Response
    {
        $links->reset('users', $request->credentials());

        return response()->noContent();
    }

    public function acceptInvitation(SetPasswordRequest $request, PasswordLinkService $links): Response
    {
        $links->reset('invitations', $request->credentials());

        return response()->noContent();
    }

    public function me(Request $request): AuthenticatedUserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new AuthenticatedUserResource($user->load('roles'));
    }
}
