<?php

declare(strict_types=1);

use App\Modules\Core\Casts\LocalDate;

it('parses strict Y-m-d dates to midnight UTC', function (): void {
    $date = LocalDate::parse('2026-03-29');

    expect($date->format('Y-m-d H:i:s e'))->toBe('2026-03-29 00:00:00 UTC');
});

it('rejects impossible or differently formatted dates', function (string $value): void {
    expect(fn () => LocalDate::parse($value))->toThrow(InvalidArgumentException::class);
})->with(['2026-02-30', '29/03/2026', '2026-13-01', 'yesterday']);

it('is unaffected by DST transitions (no time component)', function (): void {
    // Europe/Berlin switches to summer time on 2026-03-29; a calendar date must not shift.
    $before = LocalDate::parse('2026-03-28');

    expect($before->addDay()->toDateString())->toBe('2026-03-29')
        ->and($before->addDays(2)->toDateString())->toBe('2026-03-30');
});
