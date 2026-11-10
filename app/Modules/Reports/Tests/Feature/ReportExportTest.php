<?php

declare(strict_types=1);

use App\Modules\Reports\Enums\ExportStatus;
use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Services\ReportExportService;
use App\Modules\Reports\Tests\Support\ReportsTesting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class, ReportsTesting::class);

beforeEach(function (): void {
    $this->reportsWorkspace();
    $this->exports = $this->tenantUrl($this->tenant, 'reports/exports');
    $this->march = ['from' => '2026-03-01', 'to' => '2026-03-31'];
});

afterEach(function (): void {
    $this->removeTenantStorage();
});

it('queues a CSV export and lets its requester download it', function (): void {
    // Spreadsheet formula injection is neutralised in CSV.
    $this->tenant->run(fn () => $this->other->update(['first_name' => '=HYPERLINK("x")']));

    $response = $this->actingAsTenantUser($this->hr)
        ->postJson($this->exports, ['report' => 'attendance-summary', 'format' => 'csv', ...$this->march])
        ->assertStatus(202)
        ->assertJsonPath('data.report', 'attendance-summary')
        ->assertJsonPath('data.report_label', 'Attendance summary')
        ->assertJsonPath('data.file_name', 'attendance-summary_2026-03-01_2026-03-31.csv')
        // The sync queue in tests has already run the job.
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.row_count', 2)
        ->assertJsonPath('data.downloadable', true)
        ->assertJsonPath('data.expires_at', '2026-03-27T12:00:00Z');
    $id = $response->json('data.id');

    $download = $this->get("{$this->exports}/{$id}/download")->assertOk();
    expect($download->headers->get('content-type'))->toContain('text/csv')
        ->and($download->headers->get('content-disposition'))->toContain('attendance-summary_2026-03-01_2026-03-31.csv');

    $csv = $download->streamedContent();
    expect($csv)->toStartWith("\xEF\xBB\xBF\"Employee code\",Employee,\"Working days\"")
        ->and($csv)->toContain('E-1,"Ada Abbott",4,3,1')
        ->and($csv)->toContain("\"'=HYPERLINK(\"\"x\"\") Young\"");

    $this->getJson($this->exports)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    $this->getJson("{$this->exports}/{$id}")->assertOk()->assertJsonPath('data.params.from', '2026-03-01');
});

it('writes XLSX and every report kind', function (string $report, array $params): void {
    $this->actingAsTenantUser($this->hr);

    foreach (['csv', 'xlsx'] as $format) {
        $id = $this->postJson($this->exports, ['report' => $report, 'format' => $format, ...$params])
            ->assertStatus(202)->assertJsonPath('data.status', 'completed')->json('data.id');

        $file = $this->get("{$this->exports}/{$id}/download")->assertOk()->streamedContent();
        expect(substr($file, 0, 2))->toBe($format === 'xlsx' ? 'PK' : "\xEF\xBB");
    }
})->with([
    'attendance summary' => ['attendance-summary', ['from' => '2026-03-01', 'to' => '2026-03-31']],
    'register' => ['register', ['month' => '2026-03']],
    'exceptions' => ['exceptions', ['from' => '2026-03-01', 'to' => '2026-03-31', 'type' => 'late']],
    'leave' => ['leave', ['from' => '2026-03-01', 'to' => '2026-03-31']],
    'overtime' => ['overtime', ['from' => '2026-03-01', 'to' => '2026-03-31']],
]);

it('writes the register as one column per day', function (): void {
    $id = $this->actingAsTenantUser($this->hr)
        ->postJson($this->exports, ['report' => 'register', 'format' => 'csv', 'month' => '2026-03'])
        ->assertJsonPath('data.file_name', 'register_2026-03.csv')
        ->json('data.id');

    $lines = explode("\n", trim($this->get("{$this->exports}/{$id}/download")->streamedContent()));
    $header = str_getcsv(substr($lines[0], 3), ',', '"', '');
    $first = str_getcsv($lines[1], ',', '"', '');

    expect($header)->toHaveCount(2 + 31 + 5)
        ->and($header[2])->toBe('01')
        ->and(array_slice($first, 3, 6))->toBe(['P', 'L', 'A', 'LV', 'P', 'R']);
});

it('keeps exports private to their requester', function (): void {
    $id = $this->actingAsTenantUser($this->hr)
        ->postJson($this->exports, ['report' => 'leave', 'format' => 'csv', ...$this->march])->json('data.id');

    $this->actingAsTenantUser($this->manager);
    $this->getJson($this->exports)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("{$this->exports}/{$id}")->assertNotFound();
    $this->get("{$this->exports}/{$id}/download")->assertNotFound();

    $this->actingAsTenantUser($this->worker)->postJson($this->exports, ['report' => 'leave', 'format' => 'csv', ...$this->march])->assertForbidden();
    $this->getJson($this->exports)->assertForbidden();
});

it('deletes files once they expire', function (): void {
    $id = $this->actingAsTenantUser($this->hr)
        ->postJson($this->exports, ['report' => 'overtime', 'format' => 'xlsx', ...$this->march])->json('data.id');
    $path = $this->tenant->run(fn () => ReportExport::query()->findOrFail($id)->path);
    expect($this->tenant->run(fn () => Storage::disk('local')->exists($path)))->toBeTrue();

    $this->travel(6)->days();
    $this->artisan('reports:prune-exports')->assertSuccessful();
    $this->get("{$this->exports}/{$id}/download")->assertOk();

    $this->travel(2)->days();
    $this->artisan('reports:prune-exports')->assertSuccessful();

    expect($this->tenant->run(fn () => Storage::disk('local')->exists($path)))->toBeFalse();
    $this->getJson("{$this->exports}/{$id}")->assertJsonPath('data.status', 'expired')->assertJsonPath('data.downloadable', false);
    $this->get("{$this->exports}/{$id}/download")->assertStatus(410)->assertJsonPath('rule', 'export_expired');
});

it('validates the request, allows a year, and limits exports in progress', function (): void {
    $this->actingAsTenantUser($this->hr);

    $this->postJson($this->exports, [])->assertJsonValidationErrors(['report', 'format']);
    $this->postJson($this->exports, ['report' => 'register', 'format' => 'pdf', ...$this->march])->assertJsonValidationErrors(['format', 'month']);
    $this->postJson($this->exports, ['report' => 'exceptions', 'format' => 'csv', ...$this->march])->assertJsonValidationErrors('type');
    $this->postJson($this->exports, ['report' => 'leave', 'format' => 'csv', 'from' => '2025-03-01', 'to' => '2026-03-02'])->assertJsonValidationErrors('to');
    // Longer than the screen cap (92 days), within the export cap (366).
    $this->postJson($this->exports, ['report' => 'leave', 'format' => 'csv', 'from' => '2025-03-02', 'to' => '2026-03-02'])->assertStatus(202);

    $this->tenant->run(fn () => ReportExport::factory()->count(5)->create(['requested_by' => $this->hr->id]));
    $this->postJson($this->exports, ['report' => 'leave', 'format' => 'csv', ...$this->march])
        ->assertStatus(429)->assertJsonPath('rule', 'export_limit');
    $this->get("{$this->exports}/".$this->tenant->run(fn () => ReportExport::query()->where('status', 'queued')->value('id')).'/download')
        ->assertStatus(409)->assertJsonPath('rule', 'export_not_ready');
});

it('fails an export whose requester may no longer export', function (): void {
    $this->tenant->run(function (): void {
        $export = ReportExport::factory()->create(['requested_by' => $this->worker->id, 'report' => ReportKind::Leave]);
        app(ReportExportService::class)->generate($export);

        expect($export->refresh())->status->toBe(ExportStatus::Failed)
            ->error->toBe('You are no longer allowed to export reports.')
            ->path->toBeNull();
    });
});
