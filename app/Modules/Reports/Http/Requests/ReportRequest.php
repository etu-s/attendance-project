<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Reports\Report;
use App\Modules\Reports\Reports\ReportRegistry;
use App\Modules\Reports\Support\ReportParams;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /reports/dashboard and GET /reports/{report}. Which employees the
 * numbers include is decided by the viewer's visibility, not by the filters.
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reports.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ReportInput::rules($this->report()) + [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('ams.pagination.max_per_page')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            ReportInput::checkRange($validator, $this->input('from'), $this->input('to'), (int) config('ams.reports.max_view_days'));
        }];
    }

    /**
     * The report being read; null on the dashboard.
     */
    public function report(): ?Report
    {
        $kind = $this->route('report');
        $kind = $kind instanceof ReportKind ? $kind : ReportKind::tryFrom(is_string($kind) ? $kind : '');

        return $kind === null ? null : app(ReportRegistry::class)->get($kind);
    }

    public function params(): ReportParams
    {
        return ReportParams::fromArray(ReportInput::params($this->validated(), $this->report()));
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? config('ams.pagination.default_per_page'));
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
