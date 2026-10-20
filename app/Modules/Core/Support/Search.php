<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class Search
{
    /**
     * Case-insensitive "contains" across columns, matching user input literally.
     *
     * SQLite's LIKE is case-insensitive for ASCII and has no default escape
     * character, hence the explicit ESCAPE clause for the escaped wildcards.
     *
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns
     */
    public static function apply(Builder $query, array $columns, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $pattern = '%'.addcslashes($term, '%_\\').'%';

        $query->where(function (Builder $inner) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $inner->orWhereRaw($inner->getGrammar()->wrap($column)." LIKE ? ESCAPE '\\'", [$pattern]);
            }
        });
    }
}
