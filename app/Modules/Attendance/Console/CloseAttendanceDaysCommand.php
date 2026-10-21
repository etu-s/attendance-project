<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Jobs\RecalculateAttendance;
use App\Modules\Central\Enums\TenantStatus;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Support\TenantClock;
use Illuminate\Console\Command;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Scheduled every 15 minutes. A working day becomes absent or incomplete
 * only after its last punch window closes, which no punch announces; this
 * re-evaluates the last two days (and today) for everyone. Days whose result
 * did not change are not rewritten.
 */
final class CloseAttendanceDaysCommand extends Command
{
    protected $signature = 'attendance:close-days
        {--tenant=* : Only these tenant ids}
        {--days=2 : Also re-evaluate this many days before today}';

    protected $description = 'Queue the re-evaluation of recent attendance days for every active tenant';

    public function handle(): int
    {
        $only = array_values(array_filter((array) $this->option('tenant')));
        $days = max(0, (int) $this->option('days'));

        $tenants = tenancy()->query()
            ->where('status', TenantStatus::Active->value)
            ->when($only !== [], fn ($query) => $query->whereIn('id', $only))
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            $tenant->run(function () use ($days): void {
                $today = app(TenantClock::class)->today();
                RecalculateAttendance::dispatch(
                    null,
                    $today->subDays($days)->format(LocalDate::FORMAT),
                    $today->addDay()->format(LocalDate::FORMAT),
                );
            });
        }

        $this->components->info("Queued for {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
