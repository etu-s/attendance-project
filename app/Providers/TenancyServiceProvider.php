<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Central\Http\Middleware\EnsureTenantIsActive;
use App\Modules\Core\Http\Middleware\ScopeSessionToTenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;

/**
 * stancl/tenancy wiring.
 *
 * Tenant creation is NOT driven by the TenantCreated event here: it is an
 * explicit, synchronous sequence in App\Modules\Central\Services\TenantProvisioner,
 * so failures roll back cleanly. Deleting a tenant model still deletes its database.
 * Tenant routes are registered by each module (see ModuleServiceProvider).
 */
class TenancyServiceProvider extends ServiceProvider
{
    /**
     * @return array<class-string, list<mixed>>
     */
    public function events(): array
    {
        return [
            Events\TenantDeleted::class => [
                JobPipeline::make([
                    Jobs\DeleteDatabase::class,
                ])->send(function (Events\TenantDeleted $event) {
                    return $event->tenant;
                })->shouldBeQueued(false),
            ],

            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],
        ];
    }

    public function boot(): void
    {
        $this->bootEvents();
        $this->makeTenancyMiddlewareHighestPriority();
    }

    protected function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    /**
     * Order for tenant routes: identify the tenant, check its status, start the
     * SPA session (Sanctum), bind the session to the tenant, then authenticate.
     *
     * The list is rebuilt rather than prepended: statefulApi() already registers
     * Sanctum's middleware in the priority list, and prepending skips entries
     * that exist, which left ScopeSessionToTenant running before the session
     * started (so it silently did nothing). TenantIsolationTest guards this.
     */
    protected function makeTenancyMiddlewareHighestPriority(): void
    {
        $tenancyMiddleware = [
            Middleware\PreventAccessFromCentralDomains::class,
            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
            EnsureTenantIsActive::class,
            EnsureFrontendRequestsAreStateful::class,
            ScopeSessionToTenant::class,
        ];

        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $kernel->setMiddlewarePriority([
            ...$tenancyMiddleware,
            ...array_values(array_diff($kernel->getMiddlewarePriority(), $tenancyMiddleware)),
        ]);
    }
}
