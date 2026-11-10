<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use App\Modules\Core\Casts\LocalDate;
use Carbon\CarbonImmutable;

/**
 * Validated report parameters: a work-date range plus filters. The monthly
 * register gives a `month` (Y-m) instead of from/to. Stored as an array on
 * report exports, so the file is generated with exactly these parameters.
 */
final readonly class ReportParams
{
    /**
     * @param  array<string, string|int>  $extra  report-specific (type, leave_type_id)
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $departmentId = null,
        public ?int $locationId = null,
        public ?int $groupId = null,
        public ?string $search = null,
        public ?string $sort = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated input
     */
    public static function fromArray(array $input): self
    {
        if (isset($input['month'])) {
            $from = LocalDate::parse($input['month'].'-01');
            $to = $from->endOfMonth()->startOfDay();
        } else {
            $from = LocalDate::parse((string) $input['from']);
            $to = LocalDate::parse((string) $input['to']);
        }

        $int = fn (string $key): ?int => isset($input[$key]) && $input[$key] !== '' ? (int) $input[$key] : null;
        $search = trim((string) ($input['search'] ?? ''));
        $extra = [];
        foreach (['type', 'leave_type_id'] as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $extra[$key] = $key === 'type' ? (string) $input[$key] : (int) $input[$key];
            }
        }

        return new self(
            from: $from,
            to: $to,
            departmentId: $int('department_id'),
            locationId: $int('location_id'),
            groupId: $int('group_id'),
            search: $search === '' ? null : $search,
            sort: isset($input['sort']) ? (string) $input['sort'] : null,
            extra: $extra,
        );
    }

    /**
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return array_filter([
            'from' => $this->from->format(LocalDate::FORMAT),
            'to' => $this->to->format(LocalDate::FORMAT),
            'department_id' => $this->departmentId,
            'location_id' => $this->locationId,
            'group_id' => $this->groupId,
            'search' => $this->search,
            'sort' => $this->sort,
            ...$this->extra,
        ], fn ($value) => $value !== null);
    }

    /**
     * Filters in the shape the read services of other modules take.
     *
     * @return array<string, string|int>
     */
    public function filters(): array
    {
        return array_filter([
            'from' => $this->from->format(LocalDate::FORMAT),
            'to' => $this->to->format(LocalDate::FORMAT),
            'department_id' => $this->departmentId,
            'location_id' => $this->locationId,
            'group_id' => $this->groupId,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    public function sortOr(string $default): array
    {
        $sort = $this->sort ?? $default;

        return [ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc'];
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }
}
