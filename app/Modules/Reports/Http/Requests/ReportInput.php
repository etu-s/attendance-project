<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Reports\Reports\Report;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation shared by report reads, the dashboard and export requests:
 * a range (or a month for monthly reports), filters, sort, and the report's
 * own parameters. Ranges are capped (ams.reports.max_view_days on screen,
 * max_export_days for files).
 */
final class ReportInput
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(?Report $report): array
    {
        $date = 'date_format:'.LocalDate::FORMAT;
        $range = $report?->monthly()
            ? ['month' => ['required', 'date_format:Y-m']]
            : ['from' => ['required', $date], 'to' => ['required', $date, 'after_or_equal:from']];
        $sorts = $report?->sortable() ?? [];

        return $range + [
            'department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in([...$sorts, ...array_map(fn (string $s) => '-'.$s, $sorts)])],
        ] + ($report?->rules() ?? []);
    }

    public static function checkRange(Validator $validator, mixed $from, mixed $to, int $maxDays): void
    {
        if (! $validator->errors()->isEmpty() || ! is_string($from) || ! is_string($to)) {
            return;
        }

        if (LocalDate::parse($from)->diffInDays(LocalDate::parse($to)) >= $maxDays) {
            $validator->errors()->add('to', "At most {$maxDays} days at a time.");
        }
    }

    /**
     * Only the parameters a report takes, from validated input.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function params(array $validated, ?Report $report): array
    {
        return array_intersect_key($validated, self::rules($report));
    }
}
