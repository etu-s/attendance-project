<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Enums\ActorType;
use App\Modules\Core\Enums\AuditAction;
use Illuminate\Validation\Rule;

class IndexAuditLogsRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('audit-logs.view') ?? false;
    }

    protected function sortable(): array
    {
        return ['created_at' => 'created_at'];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    protected function filterRules(): array
    {
        return [
            'action' => ['sometimes', Rule::enum(AuditAction::class)],
            'actor_type' => ['sometimes', Rule::enum(ActorType::class)],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'subject_type' => ['sometimes', 'string', 'max:50', 'alpha_dash'],
            'subject_id' => ['sometimes', 'string', 'max:64'],
            // Calendar dates in the tenant's timezone (inclusive).
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }
}
