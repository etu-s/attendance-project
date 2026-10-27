<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Leave. Amounts are whole half-days (1.5 days = 3), never fractions.
 *
 * A balance is never stored: it is the sum of leave_ledger entries, and the
 * ledger is append-only (triggers abort UPDATE and DELETE). Corrections are
 * new entries (cancellation, recount, adjustment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('code', 20)->collation('nocase')->unique();
            $table->string('color', 9)->nullable();
            $table->boolean('is_paid')->default(true);
            // none, yearly (granted at the start of the leave year) or monthly.
            $table->string('accrual', 10);
            $table->unsignedSmallInteger('entitlement_halves')->default(0);
            // null = nothing carries over; otherwise the most that does.
            $table->unsignedSmallInteger('carry_forward_max_halves')->nullable();
            // How far below zero a balance may go.
            $table->unsignedSmallInteger('negative_limit_halves')->default(0);
            // No balance kept or checked (e.g. unpaid leave).
            $table->boolean('unlimited')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            // Starts at midday (first day: second half only) / ends at midday (last day: first half only).
            $table->boolean('half_start')->default(false);
            $table->boolean('half_end')->default(false);
            $table->unsignedSmallInteger('days_halves');
            $table->string('reason', 500)->nullable();
            $table->string('status', 10);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'start_date']);
            $table->index(['status', 'start_date']);
        });

        // The counted days of a request (scheduled working days only), rebuilt on recount.
        Schema::create('leave_request_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('date');
            $table->string('portion', 12);
            $table->unsignedSmallInteger('leave_year');

            $table->unique(['leave_request_id', 'date']);
            $table->index(['employee_id', 'date']);
        });

        Schema::create('leave_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->restrictOnDelete();
            // The calendar year in which the leave year starts.
            $table->unsignedSmallInteger('leave_year');
            $table->string('kind', 15);
            $table->integer('amount_halves');
            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->restrictOnDelete();
            // Accruals, grants, carry-forward and expiry are written once per period.
            $table->string('period_key', 20)->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');

            $table->unique(['employee_id', 'leave_type_id', 'kind', 'period_key']);
            $table->index(['employee_id', 'leave_type_id', 'leave_year']);
            $table->index('leave_request_id');
        });

        foreach (['UPDATE', 'DELETE'] as $operation) {
            DB::statement(sprintf(
                "CREATE TRIGGER leave_ledger_no_%s BEFORE %s ON leave_ledger BEGIN SELECT RAISE(ABORT, 'the leave ledger is append-only'); END",
                strtolower($operation),
                $operation,
            ));
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS leave_ledger_no_update');
        DB::statement('DROP TRIGGER IF EXISTS leave_ledger_no_delete');
        Schema::dropIfExists('leave_ledger');
        Schema::dropIfExists('leave_request_days');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
    }
};
