<?php

declare(strict_types=1);

namespace App\Modules\Reports\Console;

use App\Modules\Attendance\Services\AttendanceQuery;
use App\Modules\Central\Enums\TenantStatus;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Reports\Jobs\RebuildDailyStats;
use Illuminate\Console\Command;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Queues a rebuild of the attendance rollup. Needed once after installing
 * Module 8 on a workspace that already has attendance, and safe at any time
 * (the rollup is derived data). Default range: every calculated date.
 */
final class RebuildStatsCommand extends Command
{
    protected $signature = 'reports:rebuild-stats
        {--tenant=* : Only these tenant ids}
        {--from= : First work date (Y-m-d)}
        {--to= : Last work date (Y-m-d)}';

    protected $description = 'Queue a rebuild of the attendance report rollup for every active tenant';

    public function handle(AttendanceQuery $attendance): int
    {
        $only = array_values(array_filter((array) $this->option('tenant')));
        $from = $this->dateOption('from');
        $to = $this->dateOption('to');

        if ($from === false || $to === false) {
            $this->components->error('Dates must be Y-m-d.');

            return self::INVALID;
        }

        $tenants = tenancy()->query()
            ->where('status', TenantStatus::Active->value)
            ->when($only !== [], fn ($query) => $query->whereIn('id', $only))
            ->orderBy('id')
            ->get();

        $queued = 0;
        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            $queued += (int) $tenant->run(function () use ($attendance, $from, $to): bool {
                $bounds = $attendance->dateBounds();
                $first = $from !== null ? LocalDate::parse($from) : $bounds[0] ?? null;
                $last = $to !== null ? LocalDate::parse($to) : $bounds[1] ?? null;

                if ($first === null || $last === null || $first->greaterThan($last)) {
                    return false;
                }

                RebuildDailyStats::dispatch($first->format(LocalDate::FORMAT), $last->format(LocalDate::FORMAT));

                return true;
            });
        }

        $this->components->info("Queued for {$queued} of {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }

    /**
     * Null when not given, false when invalid.
     */
    private function dateOption(string $name): string|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!'.LocalDate::FORMAT, (string) $value);

        return $date !== false && $date->format(LocalDate::FORMAT) === $value ? (string) $value : false;
    }
}
