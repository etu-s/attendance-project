<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * One page of a report: rows (plain arrays, the JSON shape), pagination and
 * report-specific meta (e.g. the register's dates, the leave types).
 */
final readonly class ReportPage
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $rows,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
        public array $meta = [],
    ) {}

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    public static function of(LengthAwarePaginator $paginator, array $rows, array $meta = []): self
    {
        return new self($rows, $paginator->currentPage(), $paginator->lastPage(), $paginator->perPage(), $paginator->total(), $meta);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function toResponse(): array
    {
        $first = $this->rows === [] ? null : ($this->currentPage - 1) * $this->perPage + 1;

        return [
            'data' => $this->rows,
            'meta' => [
                'current_page' => $this->currentPage,
                'last_page' => $this->lastPage,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'from' => $first,
                'to' => $first === null ? null : $first + count($this->rows) - 1,
                ...$this->meta,
            ],
        ];
    }
}
