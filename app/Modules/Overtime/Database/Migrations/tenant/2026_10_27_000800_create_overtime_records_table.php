<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Overtime per employee and work date. `calculated` rows are derived from
 * attendance by OvertimeCalculator (one per day, rebuilt as attendance
 * changes); `manual` rows are entered by HR. Minutes are whole minutes, rates
 * whole percentages (150 = ×1.5), weighted minutes = minutes × rate / 100.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('work_date');
            $table->string('source', 10);
            // Breakdown (calculated rows); manual rows put their minutes in the column of their kind.
            $table->unsignedInteger('before_minutes')->default(0);
            $table->unsignedInteger('after_minutes')->default(0);
            $table->unsignedInteger('rest_day_minutes')->default(0);
            $table->unsignedInteger('holiday_minutes')->default(0);
            $table->unsignedInteger('calculated_minutes');
            // Rates in force when calculated (settings may change later).
            $table->unsignedSmallInteger('rate_workday');
            $table->unsignedSmallInteger('rate_rest_day');
            $table->unsignedSmallInteger('rate_holiday');
            $table->unsignedInteger('weighted_minutes');
            $table->string('status', 10);
            $table->unsignedInteger('approved_minutes')->nullable();
            $table->unsignedInteger('approved_weighted_minutes')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            // The calculation changed after approval/rejection; the record is pending again.
            $table->boolean('changed_after_decision')->default(false);
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'work_date', 'source']);
            $table->index(['status', 'work_date']);
            $table->index('work_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_records');
    }
};
