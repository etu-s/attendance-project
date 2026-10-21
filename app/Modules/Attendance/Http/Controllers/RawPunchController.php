<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Http\Requests\IndexRawPunchesRequest;
use App\Modules\Attendance\Http\Resources\RawPunchResource;
use App\Modules\Attendance\Services\RawPunchQuery;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only: raw punches can never be edited or deleted.
 */
final class RawPunchController extends Controller
{
    public function index(IndexRawPunchesRequest $request, RawPunchQuery $punches): AnonymousResourceCollection
    {
        return RawPunchResource::collection(
            $punches->paginate($request->validated(), $request->search(), $request->sort(), $request->perPage()),
        );
    }
}
