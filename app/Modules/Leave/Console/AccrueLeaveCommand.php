<?php

declare(strict_types=1);

namespace App\Modules\Leave\Console;

use App\Modules\Central\Enums\TenantStatus;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Leave\Services\LeaveAccrualService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Scheduled daily. Writes grants, accruals, carry-forward and expiry that are
 * due by today (in each tenant's timezone). Safe to run any number of times.
 */
final class AccrueLeaveCommand extends Command
{
    protected $signature = 'leave:accrue {--tenant=* : Only these tenant ids}';

    protected $description = 'Write leave grants, accruals, carry-forward and expiry due by today';

    public function handle(): int
    {
        $only = array_values(array_filter((array) $this->option('tenant')));

        $tenants = tenancy()->query()
            ->where('status', TenantStatus::Active->value)
            ->when($only !== [], fn ($query) => $query->whereIn('id', $only))
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            $written = (int) $tenant->run(fn (): int => app(LeaveAccrualService::class)->run(app(TenantClock::class)->today()));
            $this->components->twoColumnDetail('tenant '.$tenant->getTenantKey(), "{$written} entries");
        }

        return self::SUCCESS;
    }
}
