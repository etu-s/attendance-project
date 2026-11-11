<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Controllers;

use App\Modules\Central\Http\Requests\IndexCentralAuditLogsRequest;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Core\Http\Resources\AuditLogResource;
use App\Modules\Core\Services\AuditLogQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The central audit log: recorded since Module 1 on the central connection
 * (no tenant is initialized on central hosts), now readable.
 */
final class CentralAuditLogController extends Controller
{
    public function index(IndexCentralAuditLogsRequest $request, AuditLogQuery $query): AnonymousResourceCollection
    {
        return AuditLogResource::collection(
            $query->paginate($request->validated(), $request->sort(), $request->perPage(), 'UTC'),
        );
    }
}
