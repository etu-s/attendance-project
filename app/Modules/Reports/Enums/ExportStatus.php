<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/**
 * queued → running → completed (downloadable until expires_at) → expired,
 * or → failed.
 */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
