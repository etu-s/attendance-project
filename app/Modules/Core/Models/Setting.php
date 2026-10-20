<?php

declare(strict_types=1);

namespace App\Modules\Core\Models;

use App\Modules\Core\Concerns\Auditable;
use App\Modules\Core\Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-scoped key/value setting. `value` holds the JSON-encoded value so
 * strings, integers and booleans round-trip without SQLite type affinity
 * changing them; SettingsService casts it using the registered definition.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    use Auditable;

    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $fillable = ['key', 'value'];

    protected static function newFactory(): SettingFactory
    {
        return SettingFactory::new();
    }
}
