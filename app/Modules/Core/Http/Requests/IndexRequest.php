<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared contract for server-side paginated, sorted and searchable lists:
 *   ?page=2&per_page=25&sort=-created_at&search=jane
 * A leading "-" on `sort` means descending.
 */
abstract class IndexRequest extends FormRequest
{
    /**
     * Columns the client may sort by (public name => column).
     *
     * @return array<string, string>
     */
    abstract protected function sortable(): array;

    abstract protected function defaultSort(): string;

    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sorts = array_keys($this->sortable());

        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('ams.pagination.max_per_page')],
            'sort' => ['sometimes', 'string', Rule::in([...$sorts, ...array_map(fn (string $s) => '-'.$s, $sorts)])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            ...$this->filterRules(),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? config('ams.pagination.default_per_page'));
    }

    public function search(): ?string
    {
        $search = trim((string) $this->validated('search'));

        return $search === '' ? null : $search;
    }

    /**
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    public function sort(): array
    {
        $sort = (string) ($this->validated('sort') ?? $this->defaultSort());
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        return [$this->sortable()[ltrim($sort, '-')], $direction];
    }
}
