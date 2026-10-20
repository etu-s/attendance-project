<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Middleware;

use App\Modules\Central\Enums\TenantStatus;
use App\Modules\Central\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if ($tenant instanceof Tenant) {
            match ($tenant->status) {
                TenantStatus::Active => null,
                TenantStatus::Suspended => abort(403, 'This workspace is suspended. Contact your administrator.'),
                TenantStatus::Provisioning => abort(503, 'This workspace is being set up. Try again in a moment.'),
            };
        }

        return $next($request);
    }
}
