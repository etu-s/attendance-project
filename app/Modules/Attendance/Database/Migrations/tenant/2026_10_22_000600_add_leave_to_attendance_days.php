<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Approved leave on the day, as read through Core's LeaveCalendar contract
 * (Module 6). Nullable columns: SQLite adds them in place on existing files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->string('leave_name', 80)->nullable();
            // full, first_half or second_half (Core's DayPortion).
            $table->string('leave_portion', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table): void {
            $table->dropColumn(['leave_name', 'leave_portion']);
        });
    }
};
