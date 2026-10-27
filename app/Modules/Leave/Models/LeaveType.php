<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Leave\Database\Factories\LeaveTypeFactory;
use App\Modules\Leave\Enums\LeaveAccrual;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Amounts are half-days (entitlement_halves 28 = 14 days).
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $color
 * @property bool $is_paid
 * @property LeaveAccrual $accrual
 * @property int $entitlement_halves
 * @property int|null $carry_forward_max_halves
 * @property int $negative_limit_halves
 * @property bool $unlimited
 * @property bool $is_active
 */
class LeaveType extends Model
{
    use Auditable;

    /** @use HasFactory<LeaveTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'color', 'is_paid', 'accrual', 'entitlement_halves', 'carry_forward_max_halves',
        'negative_limit_halves', 'unlimited', 'is_active',
    ];

    protected $attributes = ['is_active' => true, 'is_paid' => true, 'unlimited' => false, 'accrual' => 'none'];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'accrual' => LeaveAccrual::class,
            'entitlement_halves' => 'integer',
            'carry_forward_max_halves' => 'integer',
            'negative_limit_halves' => 'integer',
            'unlimited' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): LeaveTypeFactory
    {
        return LeaveTypeFactory::new();
    }
}
