<?php

use App\Modules\Central\Http\Middleware\EnsureCentralDomain;
use App\Modules\Central\Http\Middleware\EnsureTenantIsActive;
use App\Modules\Core\Http\Middleware\ScopeSessionToTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Exceptions\NotASubdomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cookie-based Sanctum SPA auth for requests from the frontend origins.
        $middleware->statefulApi();

        // API only: never redirect unauthenticated requests to a login page.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->group('central', [
            EnsureCentralDomain::class,
        ]);

        $middleware->group('tenant', [
            InitializeTenancyBySubdomain::class,
            PreventAccessFromCentralDomains::class,
            EnsureTenantIsActive::class,
            ScopeSessionToTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Unknown subdomain or a tenant route on a central host: the workspace
        // "does not exist". The package's message names the domain; ours does not.
        $exceptions->render(fn (TenantCouldNotBeIdentifiedException|NotASubdomainException $e) => response()->json(
            ['message' => 'Workspace not found.'],
            404,
        ));
    })->create();
