<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Times are local wall-clock minutes (09:30 = 570), never strings or floats.
 * A timetable gets a real timezone only when ShiftCalendar applies it to a date.
 *
 *  fixed:    start_minute .. start_minute + duration_minutes (may cross midnight)
 *  flexible: start/duration describe the window in which required_minutes are
 *            worked; core hours (optional) are an offset + duration inside it.
 *
 * Nullable grace/window columns fall back to the workspace defaults
 * (attendance.* settings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetables', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 30)->collation('nocase')->unique();
            $table->string('type', 20);
            $table->string('color', 7)->nullable();
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('required_minutes')->nullable();
            $table->unsignedSmallInteger('core_start_offset')->nullable();
            $table->unsignedSmallInteger('core_duration_minutes')->nullable();
            $table->unsignedSmallInteger('late_grace_minutes')->nullable();
            $table->unsignedSmallInteger('early_leave_grace_minutes')->nullable();
            $table->unsignedSmallInteger('check_in_before_minutes')->nullable();
            $table->unsignedSmallInteger('check_out_after_minutes')->nullable();
            $table->unsignedSmallInteger('min_work_minutes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('timetable_breaks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('timetable_id')->constrained('timetables')->cascadeOnDelete();
            $table->string('kind', 20);
            // fixed: minutes after the timetable start; floating: null (taken anytime).
            $table->unsignedSmallInteger('start_offset')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->boolean('is_paid')->default(false);
            $table->unsignedTinyInteger('position')->default(0);

            $table->index(['timetable_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_breaks');
        Schema::dropIfExists('timetables');
    }
};
