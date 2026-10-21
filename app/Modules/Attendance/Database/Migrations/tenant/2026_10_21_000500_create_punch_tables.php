<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Punch ingestion. Timestamps are UTC `Y-m-d H:i:s` text.
 *
 * raw_punches is append-only: triggers abort every UPDATE and DELETE, so the
 * rule holds even for code that forgets it. Who a punch belongs to lives in
 * punch_matches (or orphan_punches while unknown), never on the raw row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('punch_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('kind', 20);
            // Key format ams_<prefix>_<secret>; only sha256(secret) is stored.
            $table->string('key_prefix', 16)->unique();
            $table->string('key_hash', 64);
            // Optional HMAC secret (encrypted cast) and IP allowlist (JSON list of IPs/CIDRs).
            $table->text('signing_secret')->nullable();
            $table->json('ip_allowlist')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('raw_punches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('punch_source_id')->constrained('punch_sources')->restrictOnDelete();
            $table->string('employee_identifier', 64)->collation('nocase');
            $table->dateTime('punched_at');
            $table->string('direction', 3)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->json('meta')->nullable();
            // Set at insert: older than the backlog window, kept but never counted.
            $table->boolean('too_old')->default(false);
            $table->dateTime('received_at');

            // Idempotency. NULL idempotency keys never collide in a SQLite unique index.
            $table->unique(['punch_source_id', 'employee_identifier', 'punched_at']);
            $table->unique(['punch_source_id', 'idempotency_key']);
            $table->index(['employee_identifier', 'punched_at']);
            $table->index('punched_at');
        });

        foreach (['UPDATE', 'DELETE'] as $operation) {
            DB::statement(sprintf(
                "CREATE TRIGGER raw_punches_no_%s BEFORE %s ON raw_punches BEGIN SELECT RAISE(ABORT, 'raw punches are append-only'); END",
                strtolower($operation),
                $operation,
            ));
        }

        Schema::create('employee_punch_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            // Badge / card / device user number. employee_code always works without a row here.
            $table->string('identifier', 64)->collation('nocase')->unique();
            $table->string('label', 60)->nullable();
            $table->timestamps();
        });

        Schema::create('punch_matches', function (Blueprint $table): void {
            $table->foreignId('raw_punch_id')->primary()->constrained('raw_punches')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            // Copied from the raw punch so an employee's punches are one index range.
            $table->dateTime('punched_at');
            $table->string('matched_by', 10);
            $table->dateTime('created_at');

            $table->index(['employee_id', 'punched_at']);
        });

        Schema::create('orphan_punches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('raw_punch_id')->unique()->constrained('raw_punches')->restrictOnDelete();
            $table->string('employee_identifier', 64)->collation('nocase');
            $table->string('status', 10);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'employee_identifier']);
        });

        Schema::create('attendance_engine_state', function (Blueprint $table): void {
            $table->id();
            // Raw punches cannot be flagged as processed, so the engine keeps a cursor.
            $table->unsignedBigInteger('last_raw_punch_id')->default(0);
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_engine_state');
        Schema::dropIfExists('orphan_punches');
        Schema::dropIfExists('punch_matches');
        Schema::dropIfExists('employee_punch_identifiers');
        DB::statement('DROP TRIGGER IF EXISTS raw_punches_no_update');
        DB::statement('DROP TRIGGER IF EXISTS raw_punches_no_delete');
        Schema::dropIfExists('raw_punches');
        Schema::dropIfExists('punch_sources');
    }
};
