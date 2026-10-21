<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Organization\Database\Factories\EmployeeFactory;
use App\Modules\Organization\Enums\EmployeeStatus;
use App\Modules\Organization\Enums\EmploymentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $employee_code
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $job_title
 * @property EmploymentType $employment_type
 * @property EmployeeStatus $status
 * @property CarbonImmutable $hire_date
 * @property CarbonImmutable|null $termination_date
 * @property int|null $user_id
 * @property-read EmployeeAssignment|null $currentAssignment
 * @property-read Collection<int, GroupMembership> $currentMemberships
 */
class Employee extends Model
{
    use Auditable, SoftDeletes;

    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_code', 'first_name', 'last_name', 'email', 'phone', 'job_title',
        'employment_type', 'status', 'hire_date', 'termination_date', 'user_id',
    ];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'status' => EmployeeStatus::class,
            'hire_date' => LocalDate::class,
            'termination_date' => LocalDate::class,
            'user_id' => 'integer',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * @return HasMany<EmployeeAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    /**
     * The assignment covering today in the tenant timezone (null if the employee
     * is terminated or not yet started).
     *
     * @return HasOne<EmployeeAssignment, $this>
     */
    public function currentAssignment(): HasOne
    {
        $today = app(TenantClock::class)->today()->format(LocalDate::FORMAT);

        return $this->hasOne(EmployeeAssignment::class)
            ->where('effective_from', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today));
    }

    /**
     * @return HasMany<GroupMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMembership::class);
    }

    /**
     * @return HasMany<GroupMembership, $this>
     */
    public function currentMemberships(): HasMany
    {
        $today = app(TenantClock::class)->today()->format(LocalDate::FORMAT);

        return $this->hasMany(GroupMembership::class)
            ->where('effective_from', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today));
    }

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }
}
