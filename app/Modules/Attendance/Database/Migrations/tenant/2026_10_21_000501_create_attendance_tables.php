<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Calculated attendance. Everything here is derived from raw punches,
 * approved corrections and the resolved schedule, and is rebuilt by
 * AttendanceCalculator as often as needed (same input, same rows).
 * Durations are whole minutes; instants are UTC `Y-m-d H:i:s` text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('punch_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('work_date');
            // add: a missed punch at punched_at; ignore: exclude raw_punch_id.
            $table->string('kind', 10);
            $table->dateTime('punched_at')->nullable();
            $table->foreignId('raw_punch_id')->nullable()->constrained('raw_punches')->restrictOnDelete();
            $table->string('reason', 255);
            $table->string('status', 10);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('attendance_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('work_date');
            $table->string('status', 20);
            // Snapshot of the day's placement and schedule, for filters and reports.
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('schedule_source', 20)->nullable();
            $table->string('timezone', 64);
            $table->string('holiday_name', 120)->nullable();
            $table->dateTime('first_in_at')->nullable();
            $table->dateTime('last_out_at')->nullable();
            $table->unsignedInteger('scheduled_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('unmatched_punches')->default(0);
            $table->json('flags')->nullable();
            // sha1 of the calculated result: an unchanged day is not rewritten
            // (the close-days job re-evaluates every employee every 15 minutes).
            $table->string('fingerprint', 40);
            // When the result last changed.
            $table->dateTime('calculated_at');
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index(['work_date', 'status']);
            $table->index(['work_date', 'department_id']);
        });

        Schema::create('attendance_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_day_id')->constrained('attendance_days')->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('timetable_id')->nullable()->constrained('timetables')->nullOnDelete();
            $table->string('timetable_name', 120);
            $table->boolean('flexible');
            $table->dateTime('scheduled_start_at');
            $table->dateTime('scheduled_end_at');
            $table->dateTime('in_at')->nullable();
            $table->dateTime('out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);

            $table->index('attendance_day_id');
        });

        Schema::create('attendance_punches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_day_id')->constrained('attendance_days')->cascadeOnDelete();
            $table->foreignId('attendance_period_id')->nullable()->constrained('attendance_periods')->cascadeOnDelete();
            $table->foreignId('raw_punch_id')->nullable()->constrained('raw_punches')->restrictOnDelete();
            $table->foreignId('punch_correction_id')->nullable()->constrained('punch_corrections')->restrictOnDelete();
            $table->dateTime('punched_at');
            // in, out, extra, duplicate (debounced), unmatched (outside every window), ignored (correction).
            $table->string('role', 10);

            $table->index('attendance_day_id');
            $table->index('raw_punch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
        Schema::dropIfExists('attendance_periods');
        Schema::dropIfExists('attendance_days');
        Schema::dropIfExists('punch_corrections');
    }
};
