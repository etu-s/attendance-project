<?php

declare(strict_types=1);

use App\Modules\Central\Models\SuperAdmin;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Mail\PasswordLinkMail;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['ams.frontend_url' => 'https://app.example.test:8443']);
    Mail::fake();
    $this->tenant = $this->createTenant('acme');
    $this->user = $this->createTenantUser($this->tenant, RoleName::Employee, ['email' => 'jane@acme.test']);
});

/**
 * The token and email from the link of the last mail sent to $email.
 *
 * @return array{token: string, email: string, url: string}
 */
function linkSentTo(string $email): array
{
    $mail = null;
    Mail::assertQueued(PasswordLinkMail::class, function (PasswordLinkMail $m) use ($email, &$mail): bool {
        if ($m->hasTo($email)) {
            $mail = $m;
        }

        return $m->hasTo($email);
    });
    parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $query);

    return ['token' => (string) $query['token'], 'email' => (string) $query['email'], 'url' => $mail->url];
}

describe('workspace password reset', function (): void {
    it('mails a reset link to the workspace host and lets the user choose a new password', function (): void {
        $this->postJson($this->tenantUrl($this->tenant, 'auth/forgot-password'), ['email' => 'JANE@acme.test'])
            ->assertStatus(202)
            ->assertJsonPath('message', 'If an account exists for this email, we sent a link to reset the password.');

        $link = linkSentTo('jane@acme.test');
        expect($link['url'])->toStartWith('https://acme.app.example.test:8443/reset-password?token=');

        $url = $this->tenantUrl($this->tenant, 'auth/reset-password');
        $this->postJson($url, [...$link, 'password' => 'short', 'password_confirmation' => 'short'])->assertJsonValidationErrors('password');
        $this->postJson($url, [...$link, 'token' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertJsonValidationErrors(['token' => 'invalid or has expired']);
        $this->postJson($url, [...$link, 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertNoContent();

        $this->tenant->run(fn () => expect(Hash::check('newpass123', $this->user->refresh()->password))->toBeTrue());
        // A link works once.
        $this->postJson($url, [...$link, 'password' => 'other12345', 'password_confirmation' => 'other12345'])->assertJsonValidationErrors('token');
        $this->fromSpa($this->tenant)->postJson($this->tenantUrl($this->tenant, 'auth/login'), ['email' => 'jane@acme.test', 'password' => 'newpass123'])->assertOk();
    });

    it('answers the same for unknown, deactivated and other workspaces\' emails, and sends nothing', function (): void {
        $other = $this->createTenant('globex');
        $this->createTenantUser($other, RoleName::Employee, ['email' => 'sam@globex.test']);
        $this->tenant->run(fn () => $this->user->update(['status' => UserStatus::Inactive]));

        foreach (['nobody@acme.test', 'jane@acme.test', 'sam@globex.test'] as $email) {
            $this->postJson($this->tenantUrl($this->tenant, 'auth/forgot-password'), ['email' => $email])->assertStatus(202);
        }

        Mail::assertNothingQueued();
    });

    it('expires reset links after an hour', function (): void {
        $this->postJson($this->tenantUrl($this->tenant, 'auth/forgot-password'), ['email' => 'jane@acme.test']);
        $link = linkSentTo('jane@acme.test');

        $this->travel(61)->minutes();
        $this->postJson($this->tenantUrl($this->tenant, 'auth/reset-password'), [...$link, 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertJsonValidationErrors('token');
    });
});

describe('invitations', function (): void {
    it('creates an invited user without a password and lets them set one within a week', function (): void {
        $hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
        $this->actingAsTenantUser($hr);

        $this->postJson($this->tenantUrl($this->tenant, 'users'), ['name' => 'Omar', 'email' => 'omar@acme.test', 'role' => 'employee', 'invite' => true, 'password' => 'secret123'])
            ->assertJsonValidationErrors('password');
        $this->postJson($this->tenantUrl($this->tenant, 'users'), ['name' => 'Omar', 'email' => 'omar@acme.test', 'role' => 'employee'])
            ->assertJsonValidationErrors('password');
        $this->postJson($this->tenantUrl($this->tenant, 'users'), ['name' => 'Omar', 'email' => 'omar@acme.test', 'role' => 'employee', 'invite' => true])
            ->assertCreated();

        $link = linkSentTo('omar@acme.test');
        expect($link['url'])->toStartWith('https://acme.app.example.test:8443/accept-invitation?token=');

        // A reset link is not an invitation, and the invitation is still valid after 6 days.
        $this->travel(6)->days();
        $this->postJson($this->tenantUrl($this->tenant, 'auth/accept-invitation'), [...$link, 'password' => 'welcome123', 'password_confirmation' => 'welcome123'])
            ->assertNoContent();
        $this->tenant->run(fn () => expect(Hash::check('welcome123', User::query()->where('email', 'omar@acme.test')->value('password')))->toBeTrue());
    });

    it('resends an invitation, replacing the old link, under the user rules', function (): void {
        $hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
        $admin = $this->tenantAdmin($this->tenant);

        $this->actingAsTenantUser($hr)->postJson($this->tenantUrl($this->tenant, "users/{$this->user->id}/invitation"))
            ->assertStatus(202)->assertJsonPath('message', 'Invitation sent to jane@acme.test.');
        $first = linkSentTo('jane@acme.test');
        $this->postJson($this->tenantUrl($this->tenant, "users/{$this->user->id}/invitation"))->assertStatus(202);

        $this->postJson($this->tenantUrl($this->tenant, 'auth/accept-invitation'), [...$first, 'password' => 'welcome123', 'password_confirmation' => 'welcome123'])
            ->assertJsonValidationErrors('token');

        // HR cannot invite a Tenant Admin; nobody can invite a deactivated account.
        $this->actingAsTenantUser($hr)->postJson($this->tenantUrl($this->tenant, "users/{$admin->id}/invitation"))->assertForbidden();
        $this->tenant->run(fn () => $this->user->update(['status' => UserStatus::Inactive]));
        $this->postJson($this->tenantUrl($this->tenant, "users/{$this->user->id}/invitation"))->assertStatus(409)->assertJsonPath('rule', 'user_inactive');

        $this->actingAsTenantUser($this->user)->postJson($this->tenantUrl($this->tenant, "users/{$hr->id}/invitation"))->assertForbidden();
    });

    it('keeps tokens in the workspace database: a link does not work on another workspace', function (): void {
        $other = $this->createTenant('globex');
        $this->createTenantUser($other, RoleName::Employee, ['email' => 'jane@acme.test']);

        $this->postJson($this->tenantUrl($this->tenant, 'auth/forgot-password'), ['email' => 'jane@acme.test']);
        $link = linkSentTo('jane@acme.test');

        $this->postJson($this->tenantUrl($other, 'auth/reset-password'), [...$link, 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertJsonValidationErrors('token');
    });
});

describe('super admin password reset', function (): void {
    it('mails a console link and resets the password', function (): void {
        SuperAdmin::factory()->create(['email' => 'root@ams.test']);

        $this->postJson($this->centralUrl('auth/forgot-password'), ['email' => 'root@ams.test'])->assertStatus(202);
        $this->postJson($this->centralUrl('auth/forgot-password'), ['email' => 'nobody@ams.test'])->assertStatus(202);
        $link = linkSentTo('root@ams.test');
        expect($link['url'])->toStartWith('https://app.example.test:8443/reset-password?token=');
        Mail::assertQueuedCount(1);

        $this->postJson($this->centralUrl('auth/reset-password'), [...$link, 'password' => 'console123', 'password_confirmation' => 'console123'])->assertNoContent();
        expect(Hash::check('console123', SuperAdmin::query()->where('email', 'root@ams.test')->value('password')))->toBeTrue();
        // Tenant hosts do not serve central routes.
        $this->postJson($this->tenantUrl($this->tenant, 'central/auth/forgot-password'), ['email' => 'root@ams.test'])->assertNotFound();
    });
});

it('renders the email with the link and the expiry note', function (): void {
    $html = (new PasswordLinkMail('Subject', 'Reset your password', 'Someone asked.', 'Choose a new password', 'https://acme.app.example.test/reset-password?token=abc', 'The link works for 60 minutes.'))->render();

    expect($html)->toContain('Reset your password')
        ->toContain('Choose a new password')
        ->toContain('https://acme.app.example.test/reset-password?token=abc')
        ->toContain('The link works for 60 minutes.');
});
