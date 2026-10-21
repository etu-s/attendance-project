<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Database\Seeders;

use App\Modules\Attendance\Enums\PunchSourceKind;
use App\Modules\Attendance\Services\PunchIngestor;
use App\Modules\Attendance\Services\PunchProcessor;
use App\Modules\Attendance\Services\PunchSimulator;
use App\Modules\Attendance\Services\PunchSourceService;
use App\Modules\Core\Support\TenantClock;
use Illuminate\Database\Seeder;

/**
 * Demo tenant only (tenant context): a "Main entrance" terminal (rotate its
 * key in the app to use it) and a week of simulated punches up to today, so
 * the attendance screens have something to show. Seeded, so a fresh install
 * looks the same each time for the same dates.
 */
final class DemoAttendanceSeeder extends Seeder
{
    public const SEED = 2026;

    public function run(PunchSourceService $sources, PunchSimulator $simulator, PunchIngestor $ingestor, PunchProcessor $processor, TenantClock $clock): void
    {
        $sources->create(['name' => 'Main entrance', 'kind' => PunchSourceKind::Device->value]);

        $today = $clock->today();
        $punches = $simulator->generate($today->subDays(6), $today, 0.15, 0.06, self::SEED);

        foreach (array_chunk($punches, 500) as $batch) {
            $ingestor->ingest($simulator->source(), $batch);
        }

        $processor->processPending();
    }
}
