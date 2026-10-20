<?php

declare(strict_types=1);

namespace App\Modules\Central\Services;

use App\Modules\Central\Enums\TenantStatus;
use App\Modules\Central\Models\Tenant;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Services\DatabaseBackupService;
use App\Modules\Core\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class TenantService
{
    public function __construct(
        private readonly DatabaseBackupService $backups,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage): LengthAwarePaginator
    {
        $query = Tenant::query()->with(['plan', 'domains']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['plan_id'])) {
            $query->where('plan_id', $filters['plan_id']);
        }

        if ($search !== null) {
            $query->where(function (Builder $inner) use ($search): void {
                Search::apply($inner, ['name'], $search);
                $inner->orWhereHas('domains', fn (Builder $domains) => Search::apply($domains, ['domain'], $search));
            });
        }

        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage)->withQueryString();
    }

    /**
     * @param  array{name?: string, plan_id?: int}  $attributes
     */
    public function update(Tenant $tenant, array $attributes): Tenant
    {
        $tenant->update($attributes);

        return $tenant->load(['plan', 'domains']);
    }

    public function suspend(Tenant $tenant): Tenant
    {
        if ($tenant->status !== TenantStatus::Active) {
            throw new BusinessRuleException('Only active tenants can be suspended.', 'tenant_not_active', 409);
        }

        $tenant->update(['status' => TenantStatus::Suspended]);

        return $tenant->load(['plan', 'domains']);
    }

    public function activate(Tenant $tenant): Tenant
    {
        if ($tenant->status !== TenantStatus::Suspended) {
            throw new BusinessRuleException('Only suspended tenants can be activated.', 'tenant_not_suspended', 409);
        }

        $tenant->update(['status' => TenantStatus::Active]);

        return $tenant->load(['plan', 'domains']);
    }

    /**
     * Permanently deletes the tenant and its database file. Two safeguards:
     * the tenant must be suspended first, and a VACUUM INTO backup is taken
     * before the file is removed.
     */
    public function delete(Tenant $tenant): void
    {
        if ($tenant->status !== TenantStatus::Suspended) {
            throw new BusinessRuleException('Suspend the tenant before deleting it.', 'tenant_not_suspended', 409);
        }

        $this->backups->backupTenant($tenant, $this->backups->newBackupDirectory());

        $tenant->delete();
    }
}
