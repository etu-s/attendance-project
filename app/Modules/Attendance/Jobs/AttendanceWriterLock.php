<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Jobs;

use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * One attendance writer per tenant: every job that writes attendance shares
 * this lock, so a tenant's punches and recalculations never run at the same
 * time (SQLite allows one writer per file).
 */
final class AttendanceWriterLock
{
    public static function for(string $tenantKey): WithoutOverlapping
    {
        return (new WithoutOverlapping('attendance-writer:'.$tenantKey))
            ->releaseAfter(5)
            ->expireAfter(900);
    }
}
