<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Enums\PunchDirection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A punch exactly as received. Append-only: the database refuses updates and
 * deletes (triggers), and so does this model. Corrections are separate
 * records (PunchCorrection).
 *
 * @property int $id
 * @property int $punch_source_id
 * @property string $employee_identifier
 * @property CarbonImmutable $punched_at
 * @property PunchDirection|null $direction
 * @property string|null $idempotency_key
 * @property array<string, mixed>|null $meta
 * @property bool $too_old
 * @property CarbonImmutable $received_at
 * @property-read PunchSource $source
 * @property-read PunchMatch|null $match
 * @property-read OrphanPunch|null $orphan
 */
class RawPunch extends Model
{
    /** UTC instants, stored as text so ordering and indexes work (CLAUDE.md). */
    protected $dateFormat = 'Y-m-d H:i:s';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'punch_source_id' => 'integer',
            'punched_at' => 'immutable_datetime',
            'direction' => PunchDirection::class,
            'meta' => 'array',
            'too_old' => 'boolean',
            'received_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Raw punches are append-only.'));
        static::deleting(fn () => throw new LogicException('Raw punches are append-only.'));
    }

    /**
     * @return BelongsTo<PunchSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(PunchSource::class, 'punch_source_id');
    }

    /**
     * @return HasOne<PunchMatch, $this>
     */
    public function match(): HasOne
    {
        return $this->hasOne(PunchMatch::class);
    }

    /**
     * @return HasOne<OrphanPunch, $this>
     */
    public function orphan(): HasOne
    {
        return $this->hasOne(OrphanPunch::class);
    }
}
