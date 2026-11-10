<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Identity\Models\User;
use App\Modules\Reports\Enums\ExportFormat;
use App\Modules\Reports\Enums\ExportStatus;
use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Export\TableWriter;
use App\Modules\Reports\Jobs\GenerateReportExport;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Reports\ReportRegistry;
use App\Modules\Reports\Support\ReportParams;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Report exports are always generated in the queue: the request only
 * records what to build. Files live on the tenant's local disk (suffixed per
 * tenant by the filesystem bootstrapper), are downloadable by their requester
 * only, and expire after ams.reports.export_ttl_days.
 */
final class ReportExportService
{
    /** Rows read per page while writing a file. */
    private const PAGE_SIZE = 500;

    public function __construct(
        private readonly ReportRegistry $reports,
    ) {}

    public function request(User $user, ReportKind $kind, ExportFormat $format, ReportParams $params): ReportExport
    {
        $pending = ReportExport::query()
            ->where('requested_by', $user->id)
            ->whereIn('status', [ExportStatus::Queued->value, ExportStatus::Running->value])
            ->count();

        if ($pending >= (int) config('ams.reports.max_pending_exports')) {
            throw new BusinessRuleException('You already have exports in progress. Wait for them to finish.', 'export_limit', 429);
        }

        $range = $this->reports->get($kind)->monthly()
            ? $params->from->format('Y-m')
            : $params->from->format('Y-m-d').'_'.$params->to->format('Y-m-d');

        $export = ReportExport::query()->create([
            'report' => $kind,
            'format' => $format,
            'params' => $params->toArray(),
            'status' => ExportStatus::Queued,
            'requested_by' => $user->id,
            'file_name' => "{$kind->value}_{$range}.{$format->value}",
        ]);

        GenerateReportExport::dispatch($export->id)->afterCommit();

        return $export;
    }

    /**
     * @return LengthAwarePaginator<int, ReportExport>
     */
    public function mine(User $user, int $perPage): LengthAwarePaginator
    {
        return ReportExport::query()
            ->where('requested_by', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Writes the file. Runs in the queue, with the requester's visibility as
     * it is now (a manager who lost a department no longer exports it).
     */
    public function generate(ReportExport $export): void
    {
        if ($export->status !== ExportStatus::Queued) {
            return;
        }

        $user = User::query()->find($export->requested_by);
        if ($user === null || ! $user->can('reports.export')) {
            $this->fail($export, 'You are no longer allowed to export reports.');

            return;
        }

        $export->update(['status' => ExportStatus::Running, 'started_at' => CarbonImmutable::now('UTC')]);

        $disk = Storage::disk('local');
        $path = 'report-exports/'.$export->id.'-'.Str::lower(Str::random(20)).'.'.$export->format->value;
        $disk->makeDirectory('report-exports');

        try {
            $rows = $this->write($export, $user, $disk->path($path));
        } catch (Throwable $e) {
            // Logged for operators; the user sees a generic message.
            report($e);
            $disk->delete($path);
            $this->fail($export, 'The export could not be generated.');

            return;
        }

        $now = CarbonImmutable::now('UTC');
        $export->update([
            'status' => ExportStatus::Completed,
            'path' => $path,
            'row_count' => $rows,
            'size_bytes' => $disk->size($path),
            'completed_at' => $now,
            'expires_at' => $now->addDays((int) config('ams.reports.export_ttl_days')),
        ]);
    }

    public function fail(ReportExport $export, string $message): void
    {
        $export->update(['status' => ExportStatus::Failed, 'error' => $message, 'completed_at' => CarbonImmutable::now('UTC')]);
    }

    /**
     * Deletes the files of expired exports; the rows stay as history.
     */
    public function pruneExpired(): int
    {
        $disk = Storage::disk('local');
        $count = 0;

        ReportExport::query()
            ->where('status', ExportStatus::Completed->value)
            ->where('expires_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->each(function (ReportExport $export) use ($disk, &$count): void {
                if ($export->path !== null) {
                    $disk->delete($export->path);
                }
                $export->update(['status' => ExportStatus::Expired, 'path' => null]);
                $count++;
            });

        return $count;
    }

    public function isExpired(ReportExport $export): bool
    {
        return $export->status === ExportStatus::Expired
            || ($export->expires_at !== null && $export->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC')));
    }

    private function write(ReportExport $export, User $user, string $absolutePath): int
    {
        $report = $this->reports->get($export->report);
        $params = ReportParams::fromArray($export->params);

        $page = $report->page($params, $user, self::PAGE_SIZE, 1);
        $columns = $report->columns($params, $page);

        $writer = TableWriter::open($export->format, $absolutePath);
        $writer->header(array_map('strval', array_keys($columns)));

        $rows = 0;
        for ($number = 1; ; $number++) {
            if ($number > 1) {
                $page = $report->page($params, $user, self::PAGE_SIZE, $number);
            }
            foreach ($page->rows as $row) {
                $writer->row(array_values(array_map(fn (callable $value) => $value($row), $columns)));
                $rows++;
            }
            if ($number >= $page->lastPage) {
                break;
            }
        }
        $writer->close();

        return $rows;
    }
}
