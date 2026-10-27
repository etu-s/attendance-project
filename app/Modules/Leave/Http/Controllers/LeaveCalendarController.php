<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Http\Requests\LeaveCalendarRequest;
use App\Modules\Leave\Http\Resources\LeaveRequestResource;
use App\Modules\Leave\Services\LeaveQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Who is off (pending or approved) between two dates, for the viewer's people.
 */
final class LeaveCalendarController extends Controller
{
    public function __invoke(LeaveCalendarRequest $request, LeaveQuery $query): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $department = $request->validated('department_id');

        return LeaveRequestResource::collection($query->calendar(
            $request->localDate('from'),
            $request->localDate('to'),
            $user,
            $department === null ? null : (int) $department,
        )->load(['requester', 'decider']));
    }
}
