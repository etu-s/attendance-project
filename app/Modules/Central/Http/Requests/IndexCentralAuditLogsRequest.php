<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Requests;

use App\Modules\Core\Http\Requests\IndexAuditLogsRequest;

/**
 * GET /central/audit-logs: the central audit log (plans, tenants, super
 * admins). Every signed-in super admin may read it (the route requires
 * auth:central); from/to are UTC calendar days.
 */
class IndexCentralAuditLogsRequest extends IndexAuditLogsRequest
{
    public function authorize(): bool
    {
        return $this->user('central') !== null;
    }
}
