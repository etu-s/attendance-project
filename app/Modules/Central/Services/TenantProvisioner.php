<?php

declare(strict_types=1);

namespace App\Modules\Central\Services;

use App\Modules\Central\Enums\TenantStatus;
use App\Modules\Central\Models\Tenant;
use App\Modules\Core\Events\TenantDatabaseProvisioned;
use App\Modules\Core\Events\TenantDefaultsSyncRequested;
use App\Modules\Core\Tenancy\TenantProvisioningData;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Throwable;

/**
 * Creates a tenant end to end: central rows, SQLite file, migrations, module
 * defaults and the first Tenant Admin.
 *
 * Runs synchronously: creating and migrating a SQLite file takes well under a
 * second, and the super admin gets a definitive success/failure. The central
 * write transaction only covers the two central rows; the slow file work runs
 * outside it so the central DB is never write-locked during migrations.
 * Any failure removes the tenant and its file, leaving nothing half-created.
 */
final class TenantProvisioner
{
    public function provision(TenantProvisioningData $data): Tenant
    {
        $tenant = DB::connection($this->centralConnection())->transaction(function () use ($data): Tenant {
            $tenant = Tenant::query()->create([
                'name' => $data->name,
                'plan_id' => $data->planId,
                'status' => TenantStatus::Provisioning,
            ]);

            $tenant->domains()->create(['domain' => $data->subdomain]);

            return $tenant;
        });

        try {
            dispatch_sync(new CreateDatabase($tenant));
            dispatch_sync(new MigrateDatabase($tenant));

            $tenant->run(function () use ($tenant, $data): void {
                event(new TenantDefaultsSyncRequested($tenant));
                event(new TenantDatabaseProvisioned($tenant, $data));
            });

            $tenant->update(['status' => TenantStatus::Active]);
        } catch (Throwable $e) {
            $this->discard($tenant);

            throw $e;
        }

        return $tenant->load(['plan', 'domains']);
    }

    private function discard(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        // Deleting the model fires TenantDeleted, which deletes the database file.
        $tenant->delete();
    }

    private function centralConnection(): string
    {
        return (string) config('tenancy.database.central_connection');
    }
}
