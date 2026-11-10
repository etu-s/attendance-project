<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Reports\Enums\ExportFormat;
use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Reports\Report;
use App\Modules\Reports\Reports\ReportRegistry;
use App\Modules\Reports\Support\ReportParams;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /reports/exports: {report, format, ...the report's parameters}.
 */
class CreateExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reports.export') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report' => ['required', Rule::enum(ReportKind::class)],
            'format' => ['required', Rule::enum(ExportFormat::class)],
        ] + ($this->kind() === null ? [] : ReportInput::rules($this->report()));
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            ReportInput::checkRange($validator, $this->input('from'), $this->input('to'), (int) config('ams.reports.max_export_days'));
        }];
    }

    public function kind(): ?ReportKind
    {
        return ReportKind::tryFrom((string) $this->input('report'));
    }

    public function report(): ?Report
    {
        $kind = $this->kind();

        return $kind === null ? null : app(ReportRegistry::class)->get($kind);
    }

    public function exportFormat(): ExportFormat
    {
        return ExportFormat::from((string) $this->validated('format'));
    }

    public function params(): ReportParams
    {
        return ReportParams::fromArray(ReportInput::params($this->validated(), $this->report()));
    }
}
