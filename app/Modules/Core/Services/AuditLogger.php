<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\AuditActor;
use App\Modules\Core\Enums\ActorType;
use App\Modules\Core\Enums\AuditAction;
use App\Modules\Core\Models\AuditLog;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AuditLogger
{
    public const REDACTED = '[redacted]';

    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function recordModelEvent(Model $model, AuditAction $action): void
    {
        [$before, $after] = match ($action) {
            AuditAction::Updated => $this->diff($model),
            AuditAction::Deleted => [$this->snapshot($model), null],
            default => [null, $this->snapshot($model)],
        };

        // An update that only touched ignored attributes (timestamps, last_login_at) is noise.
        if ($action === AuditAction::Updated && $before === [] && $after === []) {
            return;
        }

        $this->record($model, $action, $before, $after);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(Model $subject, AuditAction $action, ?array $before, ?array $after): AuditLog
    {
        $actor = $this->currentActor();
        $request = app()->bound('request') ? app(Request::class) : null;

        $log = new AuditLog([
            'actor_type' => $actor?->auditActorType() ?? ActorType::System,
            'actor_id' => $actor?->auditActorId(),
            'actor_name' => $actor?->auditActorName(),
            'action' => $action,
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => (string) $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent() !== null ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);

        // Write to the subject's own database so central and tenant audit trails never mix.
        $log->setConnection($subject->getConnectionName());
        $log->save();

        return $log;
    }

    /**
     * Only an actor that is already authenticated on the active guard is used.
     * Calling user() here could hit the wrong database (e.g. the tenant users
     * provider while in central context), so hasUser() is checked first.
     */
    private function currentActor(): ?AuditActor
    {
        $guard = $this->auth->guard();

        if (! $guard->hasUser()) {
            return null;
        }

        $user = $guard->user();

        return $user instanceof AuditActor ? $user : null;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function diff(Model $model): array
    {
        $before = [];
        $after = [];
        $ignored = $this->ignored($model);
        $redacted = $this->redacted($model);

        foreach (array_keys($model->getChanges()) as $key) {
            if (in_array($key, $ignored, true)) {
                continue;
            }

            if (in_array($key, $redacted, true)) {
                $before[$key] = self::REDACTED;
                $after[$key] = self::REDACTED;

                continue;
            }

            // Inside the `updated` event the original values are not yet synced.
            $before[$key] = $model->getRawOriginal($key);
            $after[$key] = $model->getAttributes()[$key] ?? null;
        }

        return [$before, $after];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Model $model): array
    {
        $ignored = $this->ignored($model);
        $redacted = $this->redacted($model);
        $snapshot = [];

        foreach ($model->getAttributes() as $key => $value) {
            if (in_array($key, $ignored, true)) {
                continue;
            }

            $snapshot[$key] = in_array($key, $redacted, true) ? self::REDACTED : $value;
        }

        return $snapshot;
    }

    /**
     * @return list<string>
     */
    private function ignored(Model $model): array
    {
        return method_exists($model, 'auditIgnoredAttributes') ? $model->auditIgnoredAttributes() : ['created_at', 'updated_at'];
    }

    /**
     * @return list<string>
     */
    private function redacted(Model $model): array
    {
        return method_exists($model, 'auditRedactedAttributes') ? $model->auditRedactedAttributes() : ['password'];
    }
}
