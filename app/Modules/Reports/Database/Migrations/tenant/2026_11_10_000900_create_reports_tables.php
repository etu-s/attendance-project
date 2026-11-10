<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * attendance_daily_stats: a rollup of calculated attendance per work date,
 * department and location (as recorded on each day). Derived data: rebuilt
 * from attendance for any date at any time (DailyStatsBuilder), so it never
 * needs a migration of its own data. 0 stands for "no department/location"
 * because SQLite treats NULLs as distinct in a unique index.
 *
 * report_exports: queued CSV/XLSX files, downloadable by their requester
 * until they expire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->date('work_date');
            $table->unsignedBigInteger('department_id')->default(0);
            $table->unsignedBigInteger('location_id')->default(0);
            $table->unsignedInteger('days')->default(0);
            foreach (['present', 'late', 'early_leave', 'late_early', 'incomplete', 'absent', 'in_progress',
                'on_leave', 'holiday', 'rest', 'day_off', 'unscheduled', 'half_day_leave', 'late_days'] as $count) {
                $table->unsignedInteger($count)->default(0);
            }
            foreach (['scheduled_minutes', 'worked_minutes', 'late_minutes', 'early_leave_minutes'] as $minutes) {
                $table->unsignedBigInteger($minutes)->default(0);
            }
            $table->dateTime('refreshed_at');

            $table->unique(['work_date', 'department_id', 'location_id']);
            $table->index(['department_id', 'work_date']);
        });

        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->string('report', 30);
            $table->string('format', 5);
            $table->json('params');
            $table->string('status', 10);
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('file_name', 150);
            $table->string('path', 255)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('error', 255)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['requested_by', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('attendance_daily_stats');
    }
};
