<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Models;

use App\Modules\Attendance\Database\Factories\PunchSourceFactory;
use App\Modules\Attendance\Enums\PunchSourceKind;
use App\Modules\Core\Concerns\Auditable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A device or integration allowed to post punches. Authenticates with
 * `Authorization: Bearer ams_<key_prefix>_<secret>`; only sha256(secret) is
 * stored. When signing_secret is set, requests must carry an HMAC signature.
 *
 * @property int $id
 * @property string $name
 * @property PunchSourceKind $kind
 * @property string $key_prefix
 * @property string $key_hash
 * @property string|null $signing_secret
 * @property list<string>|null $ip_allowlist
 * @property bool $is_active
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 */
class PunchSource extends Model
{
    use Auditable;

    /** @use HasFactory<PunchSourceFactory> */
    use HasFactory;

    /** UTC instants, stored as text so ordering and indexes work (CLAUDE.md). */
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = ['name', 'kind', 'key_prefix', 'key_hash', 'signing_secret', 'ip_allowlist', 'is_active', 'last_used_at'];

    protected $hidden = ['key_hash', 'signing_secret'];

    /** @var list<string> */
    protected array $auditIgnore = ['last_used_at'];

    /** @var list<string> */
    protected array $auditRedact = ['key_hash', 'signing_secret'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'kind' => PunchSourceKind::class,
            'signing_secret' => 'encrypted',
            'ip_allowlist' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function requiresSignature(): bool
    {
        return $this->signing_secret !== null && $this->signing_secret !== '';
    }

    protected static function newFactory(): PunchSourceFactory
    {
        return PunchSourceFactory::new();
    }
}
