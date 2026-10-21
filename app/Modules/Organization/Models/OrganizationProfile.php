<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Modules\Core\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * The tenant's business identity (single row). Behavior lives in settings.
 *
 * @property int $id
 * @property string $legal_name
 * @property string|null $display_name
 * @property string|null $registration_number
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 */
class OrganizationProfile extends Model
{
    use Auditable;

    protected $table = 'organization_profile';

    protected $fillable = ['legal_name', 'display_name', 'registration_number', 'address', 'phone', 'email'];
}
