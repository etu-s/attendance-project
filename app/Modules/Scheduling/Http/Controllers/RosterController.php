<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Support\Search;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\DepartmentTree;
use App\Modules\Organization\Services\EmployeeVisibility;
use App\Modules\Scheduling\Http\Requests\RosterRequest;
use App\Modules\Scheduling\Http\Resources\ResolvedDayPresenter;
use App\Modules\Scheduling\Services\ScheduleResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Who is scheduled when: visible employees (paginated) × the requested days.
 * Employees employed at any point in the range are included. Department and
 * group filters use the current assignment, like the employee list.
 */
final class RosterController extends Controller
{
    public function __invoke(
        RosterRequest $request,
        ScheduleResolver $resolver,
        EmployeeVisibility $visibility,
        DepartmentTree $tree,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $from = $request->from();
        $to = $request->to();

        $query = Employee::query()
            ->where('hire_date', '<=', $to->format(LocalDate::FORMAT))
            ->where(fn (Builder $q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $from->format(LocalDate::FORMAT)));
        $visibility->apply($query, $user);

        if ($request->has('department_id')) {
            $ids = $tree->withDescendants([(int) $request->validated('department_id')]);
            $query->whereHas('currentAssignment', fn (Builder $a) => $a->whereIn('department_id', $ids));
        }

        if ($request->has('group_id')) {
            $query->whereHas('currentMemberships', fn (Builder $m) => $m->where('group_id', (int) $request->validated('group_id')));
        }

        Search::apply($query, ['employee_code', 'first_name', 'last_name'], $request->validated('search'));

        $page = $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate((int) ($request->validated('per_page') ?? 25))
            ->withQueryString();

        $days = $page->isEmpty() ? [] : $resolver->forEmployees(collect($page->items()), $from, $to);

        $dates = [];
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $dates[] = $date->format(LocalDate::FORMAT);
        }

        return response()->json([
            'dates' => $dates,
            'data' => array_map(fn (Employee $e) => [
                'employee' => ['id' => $e->id, 'name' => $e->fullName(), 'employee_code' => $e->employee_code],
                'days' => ResolvedDayPresenter::many($days[$e->id] ?? []),
            ], $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }
}
