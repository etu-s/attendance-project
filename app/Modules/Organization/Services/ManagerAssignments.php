<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Core\Enums\AuditAction;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Identity\Services\UserService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manager pivots for departments and groups. Managers are user ids (Identity
 * owns users); names come from UserService. Changing managers changes who can
 * see which employees, so every change is audited.
 */
final class ManagerAssignments
{
    public function __construct(
        private readonly UserService $users,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  'department_managers'|'group_managers'  $table
     * @param  list<int>  $userIds
     */
    public function sync(Model $owner, string $table, string $ownerColumn, array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $active = $this->users->activeIdsAmong($userIds);

        if (count($active) !== count($userIds)) {
            throw ValidationException::withMessages(['user_ids' => 'Managers must be active users of this workspace.']);
        }

        DB::transaction(function () use ($owner, $table, $ownerColumn, $userIds): void {
            $before = DB::table($table)->where($ownerColumn, $owner->getKey())->orderBy('user_id')->pluck('user_id')
                ->map(fn ($id) => (int) $id)->all();

            DB::table($table)->where($ownerColumn, $owner->getKey())->delete();
            DB::table($table)->insert(array_map(fn (int $id) => [$ownerColumn => $owner->getKey(), 'user_id' => $id], $userIds));

            sort($userIds);
            if ($before !== $userIds) {
                $this->audit->record($owner, AuditAction::Updated, ['managers' => $before], ['managers' => $userIds]);
            }
        });
    }

    /**
     * Manager summaries for many owners at once (no N+1 in lists).
     *
     * @param  list<int>  $ownerIds
     * @return array<int, list<array{id: int, name: string, email: string, status: string}>> owner id => managers
     */
    public function forOwners(string $table, string $ownerColumn, array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $rows = DB::table($table)->whereIn($ownerColumn, $ownerIds)->get([$ownerColumn, 'user_id']);
        $summaries = $this->users->summaries($rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        $result = [];
        foreach ($rows as $row) {
            $summary = $summaries[(int) $row->user_id] ?? null;
            if ($summary !== null) {
                $result[(int) $row->{$ownerColumn}][] = $summary;
            }
        }

        return $result;
    }
}
