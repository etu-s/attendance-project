<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Modules\Identity\Models\User;
use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Support\ReportPage;
use App\Modules\Reports\Support\ReportParams;

/**
 * A tabular report: read page by page through the API, and written whole to
 * a file by the export job, from the same rows. Every implementation scopes
 * to the viewer's employees (EmployeeVisibility, through the read services
 * of the module that owns the data).
 */
interface Report
{
    public function kind(): ReportKind;

    /**
     * Validation rules for report-specific parameters (besides the shared
     * range and filters).
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Whether the report takes a month (Y-m) instead of from/to.
     */
    public function monthly(): bool;

    /**
     * Public sort keys (prefix "-" for descending).
     *
     * @return list<string>
     */
    public function sortable(): array;

    public function defaultSort(): string;

    public function page(ReportParams $params, User $viewer, int $perPage, int $page): ReportPage;

    /**
     * Export columns: header => value of one row (a scalar or null).
     *
     * @return array<string, callable(array<string, mixed>): (string|int|float|null)>
     */
    public function columns(ReportParams $params, ReportPage $firstPage): array;
}
