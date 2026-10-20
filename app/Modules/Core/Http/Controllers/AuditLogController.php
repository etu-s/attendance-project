<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Http\Requests\IndexAuditLogsRequest;
use App\Modules\Core\Http\Resources\AuditLogResource;
use App\Modules\Core\Services\AuditLogQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AuditLogController extends Controller
{
    public function index(IndexAuditLogsRequest $request, AuditLogQuery $query): AnonymousResourceCollection
    {
        return AuditLogResource::collection(
            $query->paginate($request->validated(), $request->sort(), $request->perPage()),
        );
    }
}
