<?php

declare(strict_types=1);

namespace App\Modules\Leave\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LedgerKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One credit (+) or debit (−) in half-days. Append-only: the database refuses
 * updates and deletes (triggers), and so does this model.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $leave_type_id
 * @property int $leave_year
 * @property LedgerKind $kind
 * @property int $amount_halves
 * @property int|null $leave_request_id
 * @property string|null $period_key
 * @property string|null $reason
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read LeaveType $type
 * @property-read User|null $creator
 */
class LeaveLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    /** UTC instants, stored as text so ordering and indexes work (CLAUDE.md). */
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $table = 'leave_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'leave_type_id' => 'integer',
            'leave_year' => 'integer',
            'kind' => LedgerKind::class,
            'amount_halves' => 'integer',
            'leave_request_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The leave ledger is append-only.'));
        static::deleting(fn () => throw new LogicException('The leave ledger is append-only.'));
    }

    /**
     * @return BelongsTo<LeaveType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
