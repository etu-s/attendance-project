<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Effective-dated, like Organization's history: periods run from
 * effective_from to effective_to inclusive (null = open-ended) and never
 * overlap for one target (ScheduleAssignmentService).
 *
 * target_type organization has target_id null (the default schedule);
 * department/group/employee targets point at Organization rows. That
 * reference is not a foreign key because it is polymorphic; the resolver
 * ignores rows whose target no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            // The date the shift's cycle starts (day 1 / week 1) for this target.
            $table->date('anchor_date');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['target_type', 'target_id', 'effective_from']);
            $table->index(['effective_from', 'effective_to']);
            $table->index('shift_id');
        });

        Schema::create('temporary_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            // null shift = day off for the whole range.
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->date('anchor_date')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'date_from']);
            $table->index(['date_from', 'date_to']);
            $table->index('shift_id');
        });

        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('name', 120);
            // null = the whole workspace; otherwise employees at that location only.
            $table->foreignId('location_id')->nullable()->constrained('locations')->cascadeOnDelete();
            // Fixed-date holidays repeat on the same month/day every later year.
            $table->boolean('recurs_yearly')->default(false);
            $table->timestamps();

            $table->index('date');
            $table->index(['recurs_yearly', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('temporary_schedules');
        Schema::dropIfExists('schedule_assignments');
    }
};
