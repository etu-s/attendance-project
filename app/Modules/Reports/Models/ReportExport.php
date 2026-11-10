<?php

declare(strict_types=1);

namespace App\Modules\Reports\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Reports\Database\Factories\ReportExportFactory;
use App\Modules\Reports\Enums\ExportFormat;
use App\Modules\Reports\Enums\ExportStatus;
use App\Modules\Reports\Enums\ReportKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A queued report file. `params` are the validated report parameters; the
 * report is generated with the requester's visibility at generation time.
 * `path` is relative to the tenant's local disk.
 *
 * @property int $id
 * @property ReportKind $report
 * @property ExportFormat $format
 * @property array<string, mixed> $params
 * @property ExportStatus $status
 * @property int $requested_by
 * @property string $file_name
 * @property string|null $path
 * @property int|null $row_count
 * @property int|null $size_bytes
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 * @property-read User $requester
 */
class ReportExport extends Model
{
    /** @use HasFactory<ReportExportFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'report' => ReportKind::class,
            'format' => ExportFormat::class,
            'params' => 'array',
            'status' => ExportStatus::class,
            'requested_by' => 'integer',
            'row_count' => 'integer',
            'size_bytes' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ReportExportFactory
    {
        return ReportExportFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::Completed && $this->path !== null;
    }
}
