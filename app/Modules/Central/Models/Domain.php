<?php

declare(strict_types=1);

namespace App\Modules\Central\Models;

use App\Modules\Core\Concerns\Auditable;
use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/**
 * A tenant's subdomain label only (e.g. `acme`, not `acme.localhost`);
 * InitializeTenancyBySubdomain matches on the first host label.
 *
 * @property int $id
 * @property string $domain
 * @property string $tenant_id
 */
class Domain extends BaseDomain
{
    use Auditable;
}
