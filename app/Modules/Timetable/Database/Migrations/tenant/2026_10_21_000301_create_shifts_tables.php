<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A shift is a repeating cycle of cycle_length days (cycle_unit = day) or
 * weeks (cycle_unit = week, day_index 0 = Monday of the first week). Each
 * cycle day holds 0..3 timetables; a day without one is a rest day. Where the
 * cycle starts (its anchor date) is set when the shift is scheduled (Module 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 30)->collation('nocase')->unique();
            $table->string('cycle_unit', 10);
            $table->unsignedSmallInteger('cycle_length');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('shift_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->unsignedSmallInteger('day_index');
            // A timetable in any shift cannot be deleted (deactivate it instead).
            $table->foreignId('timetable_id')->constrained('timetables')->restrictOnDelete();

            $table->unique(['shift_id', 'day_index', 'timetable_id']);
            $table->index('timetable_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_days');
        Schema::dropIfExists('shifts');
    }
};
