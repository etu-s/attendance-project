<?php

declare(strict_types=1);

namespace App\Modules\Organization\Listeners;

use App\Modules\Identity\Events\UserDeleted;
use App\Modules\Organization\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * A deleted login no longer manages anything and no longer belongs to an
 * employee. The employee record itself stays (it is HR history).
 */
final class ReleaseDeletedUser
{
    public function handle(UserDeleted $event): void
    {
        Employee::withTrashed()->where('user_id', $event->userId)->get()
            ->each(fn (Employee $employee) => $employee->update(['user_id' => null]));

        DB::table('department_managers')->where('user_id', $event->userId)->delete();
        DB::table('group_managers')->where('user_id', $event->userId)->delete();
    }
}
