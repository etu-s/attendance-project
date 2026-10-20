<?php

declare(strict_types=1);

use App\Modules\Central\Models\Plan;
use App\Modules\Core\Support\Search;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('matches case-insensitively and treats LIKE wildcards literally', function (): void {
    Plan::factory()->create(['name' => 'Gold 100%']);
    Plan::factory()->create(['name' => 'Gold 1000']);
    Plan::factory()->create(['name' => 'snake_case']);
    Plan::factory()->create(['name' => 'snakeXcase']);

    $search = function (string $term): array {
        $query = Plan::query();
        Search::apply($query, ['name'], $term);

        return $query->orderBy('name')->pluck('name')->all();
    };

    expect($search('100%'))->toBe(['Gold 100%'])
        ->and($search('e_c'))->toBe(['snake_case'])
        ->and($search('GOLD'))->toBe(['Gold 100%', 'Gold 1000']);
});

it('is a no-op for an empty term', function (): void {
    Plan::factory()->count(2)->create();
    $query = Plan::query();

    Search::apply($query, ['name'], null);

    expect($query->count())->toBe(2);
});
