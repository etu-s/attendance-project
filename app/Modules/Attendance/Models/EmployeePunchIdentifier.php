<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Organization\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extra identifier (badge, card, device user number) that punches may
 * arrive with. Unique across the workspace and never equal to another
 * employee's code. Matching happens on arrival (PunchMatch), so removing or
 * reassigning an identifier does not move punches already matched.
 *
 * @property int $id
 * @property int $employee_id
 * @property string $identifier
 * @property string|null $label
 */
class EmployeePunchIdentifier extends Model
{
    use Auditable;

    protected $fillable = ['employee_id', 'identifier', 'label'];

    protected function casts(): array
    {
        return ['employee_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
