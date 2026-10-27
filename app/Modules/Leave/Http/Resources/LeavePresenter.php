<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Resources;

use App\Modules\Core\Enums\DayPortion;
use App\Modules\Leave\Models\LeaveLedgerEntry;
use App\Modules\Leave\Models\LeaveType;

/**
 * JSON shapes for balances, ledger entries and previews. Half-days are shown
 * as days (3 → 1.5).
 */
final class LeavePresenter
{
    /**
     * @param  list<array{type: LeaveType, entitled: int, used: int, adjusted: int, expired: int, balance: int, pending: int, available: int|null}>  $balances
     * @return list<array<string, mixed>>
     */
    public static function balances(array $balances): array
    {
        return array_map(fn (array $b) => [
            'type' => LeaveTypeResource::brief($b['type']),
            'entitled' => $b['entitled'] / 2,
            'used' => $b['used'] / 2,
            'adjusted' => $b['adjusted'] / 2,
            'expired' => $b['expired'] / 2,
            'balance' => $b['balance'] / 2,
            'pending' => $b['pending'] / 2,
            'available' => $b['available'] === null ? null : $b['available'] / 2,
        ], $balances);
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(LeaveLedgerEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'type' => LeaveTypeResource::brief($entry->type),
            'leave_year' => $entry->leave_year,
            'kind' => $entry->kind->value,
            'days' => $entry->amount_halves / 2,
            'leave_request_id' => $entry->leave_request_id,
            'reason' => $entry->reason,
            'created_by' => $entry->creator === null ? null : ['id' => $entry->creator->id, 'name' => $entry->creator->name],
            'created_at' => $entry->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * @param  array{days: list<array{date: string, portion: DayPortion, leave_year: int}>, halves: int, years: list<array{leave_year: int, halves: int, available: int|null, after: int|null}>, problem: string|null}  $preview
     * @return array<string, mixed>
     */
    public static function preview(array $preview): array
    {
        return [
            'days' => $preview['halves'] / 2,
            'counted_days' => array_map(fn (array $d) => ['date' => $d['date'], 'portion' => $d['portion']->value], $preview['days']),
            'years' => array_map(fn (array $y) => [
                'leave_year' => $y['leave_year'],
                'days' => $y['halves'] / 2,
                'available' => $y['available'] === null ? null : $y['available'] / 2,
                'after' => $y['after'] === null ? null : $y['after'] / 2,
            ], $preview['years']),
            'problem' => $preview['problem'],
        ];
    }
}
