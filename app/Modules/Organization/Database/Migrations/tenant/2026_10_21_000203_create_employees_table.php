<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            // The identifier punches arrive with (Module 5). NOCASE + unique across
            // soft-deleted rows, so a code is never reused for a different person.
            $table->string('employee_code', 30)->collation('nocase')->unique();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('email')->collation('nocase')->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('employment_type', 20);
            $table->string('status', 20)->default('active')->index();
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            // Optional login; one employee per user.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
