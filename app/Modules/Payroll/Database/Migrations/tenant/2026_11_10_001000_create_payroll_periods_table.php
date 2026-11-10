<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Payroll periods: a range of finished work dates that is frozen once
 * locked. Locked periods never overlap (checked in PayrollPeriodService).
 * An unlocked period keeps its row, who unlocked it and why, and can be
 * locked again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 10);
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('locked_at')->nullable();
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('unlocked_at')->nullable();
            $table->string('unlock_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
