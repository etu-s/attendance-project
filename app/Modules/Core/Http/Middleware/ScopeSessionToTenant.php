<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds a session to the tenant it was created on.
 *
 * Sessions are stored outside tenant databases, and the session guard only
 * stores a user id. Without this check, a session cookie from tenant A replayed
 * against tenant B would authenticate as whichever B user has the same id.
 * A mismatched session is invalidated (never reused, not even as a guest session).
 *
 * Must run after the session starts and tenancy is initialized, and before
 * authentication (see TenancyServiceProvider middleware priority).
 */
final class ScopeSessionToTenant
{
    public const SESSION_KEY = '_tenant';

    public function __construct(
        private readonly AuthManager $auth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! tenancy()->initialized) {
            return $next($request);
        }

        $session = $request->session();
        $current = (string) tenant()->getTenantKey();
        $bound = $session->get(self::SESSION_KEY);

        if (is_string($bound) && ! hash_equals($bound, $current)) {
            $session->invalidate();

            // Sanctum's AuthenticateSession may already have resolved the session's
            // user into the guard earlier in this request; drop that in-memory user
            // too. (Not logout(): that would rotate the other tenant user's remember token.)
            $this->auth->forgetGuards();
        }

        $session->put(self::SESSION_KEY, $current);

        return $next($request);
    }
}
