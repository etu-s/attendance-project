<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Services\PunchIngestor;
use App\Modules\Attendance\Services\PunchProcessor;
use App\Modules\Attendance\Services\PunchSimulator;
use App\Modules\Central\Models\Domain;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Core\Support\TenantClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Local development / demos: punches for every employee from their real
 * schedule. By default it ingests through the same service as the API and
 * processes the punches right away (no queue worker needed). With --http it
 * posts to the running API with a punch source key instead, exercising auth,
 * validation and the queue end to end.
 */
final class SimulatePunchesCommand extends Command
{
    protected $signature = 'punches:simulate
        {--tenant=acme : Tenant id or subdomain}
        {--from= : First work date (Y-m-d, default: 6 days ago)}
        {--to= : Last work date (Y-m-d, default: today)}
        {--late-rate=0.15 : Share of late arrivals (early leavers: half of it)}
        {--missing-rate=0.06 : Share of missed punches (absences: half of it)}
        {--seed= : Random seed (same seed = same punches)}
        {--http : Post to the API instead of ingesting directly}
        {--key= : Punch source key for --http}
        {--url= : API origin for --http (default: http://<subdomain>.localhost:8010)}';

    protected $description = 'Generate realistic punches from employee schedules';

    public function handle(): int
    {
        $tenant = $this->tenant((string) $this->option('tenant'));
        if ($tenant === null) {
            $this->components->error('Tenant not found.');

            return self::FAILURE;
        }

        if ($this->option('http') && ! $this->option('key')) {
            $this->components->error('--http needs --key (create a punch source in the app to get one).');

            return self::FAILURE;
        }

        return (int) $tenant->run(fn (): int => $this->simulate($tenant));
    }

    private function simulate(Tenant $tenant): int
    {
        $today = app(TenantClock::class)->today();
        $from = $this->option('from') ? LocalDate::parse((string) $this->option('from')) : $today->subDays(6);
        $to = $this->option('to') ? LocalDate::parse((string) $this->option('to')) : $today;
        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : random_int(1, PHP_INT_MAX);

        $simulator = app(PunchSimulator::class);
        $punches = $simulator->generate($from, $to, (float) $this->option('late-rate'), (float) $this->option('missing-rate'), $seed);
        $this->components->info(sprintf('%d punches for %s to %s (seed %d).', count($punches), $from->toDateString(), $to->toDateString(), $seed));

        $batchSize = (int) app(SettingsService::class)->get('attendance.max_batch_size');
        $counts = ['accepted' => 0, 'duplicate' => 0, 'invalid' => 0];

        foreach (array_chunk($punches, $batchSize) as $batch) {
            $statuses = $this->option('http')
                ? $this->post($tenant, $batch)
                : array_column(app(PunchIngestor::class)->ingest($simulator->source(), $batch), 'status');

            foreach ($statuses as $status) {
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            }
        }

        $this->components->twoColumnDetail('Accepted', (string) $counts['accepted']);
        $this->components->twoColumnDetail('Duplicates', (string) $counts['duplicate']);
        $this->components->twoColumnDetail('Invalid', (string) $counts['invalid']);

        if (! $this->option('http')) {
            $processed = app(PunchProcessor::class)->processPending();
            $this->components->info("Processed {$processed} punch(es).");
        } else {
            $this->components->info('Processing runs on the queue (php artisan queue:work).');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, string>>  $batch
     * @return list<string>
     */
    private function post(Tenant $tenant, array $batch): array
    {
        $origin = (string) ($this->option('url') ?: 'http://'.$tenant->domains->first()?->domain.'.localhost:8010');
        $response = Http::withToken((string) $this->option('key'))
            ->acceptJson()
            ->post(rtrim($origin, '/').'/api/v1/punches/batch', ['punches' => $batch]);

        if (! $response->successful()) {
            $this->components->error("The API answered {$response->status()}: ".$response->json('message', ''));

            return array_fill(0, count($batch), 'invalid');
        }

        return array_column((array) $response->json('data'), 'status');
    }

    private function tenant(string $idOrSubdomain): ?Tenant
    {
        /** @var Tenant|null $tenant */
        $tenant = tenancy()->find($idOrSubdomain)
            ?? Domain::query()->where('domain', $idOrSubdomain)->first()?->tenant;

        return $tenant;
    }
}
