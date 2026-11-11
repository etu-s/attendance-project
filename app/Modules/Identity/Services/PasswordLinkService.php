<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Central\Models\Tenant;
use App\Modules\Core\Mail\PasswordLinkMail;
use App\Modules\Core\Support\FrontendUrl;
use App\Modules\Core\Support\PasswordBrokers;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Password reset and invitation links for workspace users (approved for
 * Module 9). Tokens live in the tenant's own password_reset_tokens table.
 *
 *  - "Forgot password" answers the same whether or not the email exists
 *    (no account enumeration) and only mails active accounts.
 *  - An invitation is a first-password link valid for a week (broker
 *    `invitations`); the account exists but nobody knows its password.
 *  - Setting a password signs the user out of API tokens.
 */
final class PasswordLinkService
{
    public function sendReset(string $email): void
    {
        $user = User::query()->where('email', mb_strtolower($email))->first();

        if ($user === null || $user->status !== UserStatus::Active) {
            return;
        }

        $this->broker('users')->sendResetLink(['email' => $user->email], function (User $user, string $token): void {
            Mail::to($user->email)->queue(new PasswordLinkMail(
                subjectLine: 'Reset your '.config('app.name').' password',
                heading: 'Reset your password',
                intro: 'Someone asked to reset the password of your account in the '.$this->workspaceName().' workspace.',
                actionLabel: 'Choose a new password',
                url: FrontendUrl::tenant($this->subdomain(), 'reset-password', ['token' => $token, 'email' => $user->email]),
                footnote: 'The link works for 60 minutes. If you did not ask for it, ignore this email; your password stays the same.',
            ));
        });
    }

    public function sendInvitation(User $user): void
    {
        // A fresh broker token replaces any earlier link for this email.
        $token = $this->broker('invitations')->createToken($user);

        Mail::to($user->email)->queue(new PasswordLinkMail(
            subjectLine: 'You are invited to '.$this->workspaceName(),
            heading: 'Welcome, '.$user->name,
            intro: 'An account was created for you in the '.$this->workspaceName().' workspace. Set your password to sign in.',
            actionLabel: 'Set your password',
            url: FrontendUrl::tenant($this->subdomain(), 'accept-invitation', ['token' => $token, 'email' => $user->email]),
            footnote: 'The link works for 7 days. Ask your administrator for a new one if it has expired.',
        ));
    }

    /**
     * A password nobody knows, for an invited account until the invitation
     * is accepted.
     */
    public static function unusablePassword(): string
    {
        return Str::password(48);
    }

    /**
     * @param  'users'|'invitations'  $broker
     * @param  array{email: string, token: string, password: string}  $data
     */
    public function reset(string $broker, array $data): void
    {
        $status = $this->broker($broker)->reset($data, function (User $user, string $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'token' => $broker === 'invitations'
                    ? 'This invitation link is invalid or has expired. Ask your administrator for a new one.'
                    : 'This reset link is invalid or has expired. Ask for a new one.',
            ]);
        }
    }

    private function broker(string $name): PasswordBroker
    {
        return PasswordBrokers::make($name);
    }

    private function subdomain(): string
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return (string) $tenant->subdomain();
    }

    private function workspaceName(): string
    {
        return (string) (tenant('name') ?? config('app.name'));
    }
}
