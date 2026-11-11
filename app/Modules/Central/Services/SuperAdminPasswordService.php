<?php

declare(strict_types=1);

namespace App\Modules\Central\Services;

use App\Modules\Central\Models\SuperAdmin;
use App\Modules\Core\Mail\PasswordLinkMail;
use App\Modules\Core\Support\FrontendUrl;
use App\Modules\Core\Support\PasswordBrokers;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Forgot password" for super admins (central DB, broker `super_admins`).
 * The answer never reveals whether an email has an account.
 */
final class SuperAdminPasswordService
{
    public function sendReset(string $email): void
    {
        PasswordBrokers::make('super_admins')->sendResetLink(
            ['email' => mb_strtolower($email)],
            function (SuperAdmin $admin, string $token): void {
                Mail::to($admin->email)->queue(new PasswordLinkMail(
                    subjectLine: 'Reset your '.config('app.name').' console password',
                    heading: 'Reset your password',
                    intro: 'Someone asked to reset the password of your super admin account.',
                    actionLabel: 'Choose a new password',
                    url: FrontendUrl::central('reset-password', ['token' => $token, 'email' => $admin->email]),
                    footnote: 'The link works for 60 minutes. If you did not ask for it, ignore this email.',
                ));
            },
        );
    }

    /**
     * @param  array{email: string, token: string, password: string}  $data
     */
    public function reset(array $data): void
    {
        $status = PasswordBrokers::make('super_admins')->reset($data, function (SuperAdmin $admin, string $password): void {
            $admin->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => 'This reset link is invalid or has expired. Ask for a new one.']);
        }
    }
}
