<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            // A parent with children cannot be deleted (cycles are prevented in DepartmentService).
            $table->foreignId('parent_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 30)->collation('nocase')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index('parent_id');
            $table->index('name');
        });

        Schema::create('department_managers', function (Blueprint $table): void {
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->primary(['department_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_managers');
        Schema::dropIfExists('departments');
    }
};
