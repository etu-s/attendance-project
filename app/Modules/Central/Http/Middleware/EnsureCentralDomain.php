<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central (super admin) routes answer only on central hosts. On a tenant
 * subdomain they do not exist (404), rather than being forbidden.
 */
final class EnsureCentralDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getHost(), (array) config('tenancy.central_domains'), true)) {
            abort(404);
        }

        return $next($request);
    }
}
