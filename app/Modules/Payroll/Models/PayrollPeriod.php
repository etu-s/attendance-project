<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Concerns\Auditable;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Database\Factories\PayrollPeriodFactory;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payroll period. While locked, its work dates are frozen (see
 * Core\Contracts\PeriodLock). Every lock and unlock is audit-logged.
 *
 * @property int $id
 * @property string $name
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable $end_date
 * @property PayrollPeriodStatus $status
 * @property int|null $locked_by
 * @property CarbonImmutable|null $locked_at
 * @property int|null $unlocked_by
 * @property CarbonImmutable|null $unlocked_at
 * @property string|null $unlock_reason
 * @property-read User|null $locker
 * @property-read User|null $unlocker
 */
class PayrollPeriod extends Model
{
    use Auditable;

    /** @use HasFactory<PayrollPeriodFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => LocalDate::class,
            'end_date' => LocalDate::class,
            'status' => PayrollPeriodStatus::class,
            'locked_by' => 'integer',
            'unlocked_by' => 'integer',
            'locked_at' => 'immutable_datetime',
            'unlocked_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): PayrollPeriodFactory
    {
        return PayrollPeriodFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function unlocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by')->withTrashed();
    }

    public function isLocked(): bool
    {
        return $this->status === PayrollPeriodStatus::Locked;
    }
}
