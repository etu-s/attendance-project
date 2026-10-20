<?php

declare(strict_types=1);

namespace App\Modules\Core\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit_logs table exists in the central DB (tenants, plans, super admins)
 * and in every tenant DB (users, settings, ...). One definition keeps both in sync.
 */
final class AuditLogSchema
{
    public static function create(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('action', 30);
            $table->string('auditable_type', 50);
            $table->string('auditable_id', 64);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at');

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['created_at']);
            $table->index(['actor_type', 'actor_id']);
            $table->index(['action']);
        });
    }

    public static function drop(): void
    {
        Schema::dropIfExists('audit_logs');
    }
}
