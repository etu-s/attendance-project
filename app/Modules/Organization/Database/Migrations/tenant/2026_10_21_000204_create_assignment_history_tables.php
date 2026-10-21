<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Effective-dated history. A row is valid from effective_from to effective_to
 * inclusive (null = open-ended). Periods for one employee never overlap
 * (enforced in AssignmentService / MembershipService). Attendance and
 * scheduling resolve "department/location/groups on date D" from these rows,
 * so past days keep the structure they actually had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from']);
            $table->index(['department_id', 'effective_from', 'effective_to']);
            $table->index('location_id');
        });

        Schema::create('group_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignId('group_id')->constrained('groups')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'group_id', 'effective_from']);
            $table->index(['group_id', 'effective_from', 'effective_to']);
            $table->index(['employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_memberships');
        Schema::dropIfExists('employee_assignments');
    }
};
