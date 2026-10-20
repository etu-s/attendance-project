<?php

declare(strict_types=1);

namespace App\Modules\Core\Concerns;

use App\Modules\Core\Enums\AuditAction;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Records created/updated/deleted/restored events to audit_logs on the same
 * connection as the model (central models -> central DB, tenant models -> tenant DB).
 *
 * Models may declare:
 *   protected array $auditIgnore = [...];  // never logged (noise such as last_login_at)
 *   protected array $auditRedact = [...];  // logged as "[redacted]" (secrets such as password)
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => app(AuditLogger::class)->recordModelEvent($model, AuditAction::Created));
        static::updated(fn (Model $model) => app(AuditLogger::class)->recordModelEvent($model, AuditAction::Updated));
        static::deleted(fn (Model $model) => app(AuditLogger::class)->recordModelEvent($model, AuditAction::Deleted));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => app(AuditLogger::class)->recordModelEvent($model, AuditAction::Restored));
        }
    }

    /**
     * @return list<string>
     */
    public function auditIgnoredAttributes(): array
    {
        return array_values(array_unique([
            'created_at',
            'updated_at',
            'remember_token',
            ...(property_exists($this, 'auditIgnore') ? $this->auditIgnore : []),
        ]));
    }

    /**
     * @return list<string>
     */
    public function auditRedactedAttributes(): array
    {
        return array_values(array_unique([
            'password',
            ...(property_exists($this, 'auditRedact') ? $this->auditRedact : []),
        ]));
    }
}
