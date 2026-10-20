<?php

declare(strict_types=1);

namespace App\Modules\Central\Models;

use App\Modules\Central\Database\Factories\TenantFactory;
use App\Modules\Central\Enums\TenantStatus;
use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Central record of a tenant. Its data lives in its own SQLite file
 * (see TenantSqliteDatabaseManager); its subdomain lives in `domains`.
 *
 * @property string $id
 * @property string $name
 * @property int $plan_id
 * @property TenantStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Plan $plan
 * @property-read Collection<int, Domain> $domains
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use Auditable;

    /** @use HasFactory<TenantFactory> */
    use HasDatabase, HasDomains, HasFactory;

    /** @var list<string> internal tenancy bookkeeping (db name), not business data */
    protected array $auditIgnore = ['data'];

    public static function getCustomColumns(): array
    {
        return ['id', 'name', 'plan_id', 'status', 'created_at', 'updated_at'];
    }

    protected function casts(): array
    {
        return [
            'plan_id' => 'integer',
            'status' => TenantStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subdomain(): ?string
    {
        return $this->domains->first()?->domain;
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
