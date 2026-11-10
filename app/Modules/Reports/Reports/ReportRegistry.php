<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Modules\Reports\Enums\ReportKind;
use Illuminate\Contracts\Container\Container;

final class ReportRegistry
{
    private const CLASSES = [
        'attendance-summary' => AttendanceSummaryReport::class,
        'register' => RegisterReport::class,
        'exceptions' => ExceptionsReport::class,
        'leave' => LeaveReport::class,
        'overtime' => OvertimeReport::class,
    ];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function get(ReportKind $kind): Report
    {
        /** @var Report */
        return $this->container->make(self::CLASSES[$kind->value]);
    }
}
