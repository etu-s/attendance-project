<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name', 120);
            // A plan in use cannot be deleted.
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 20)->default('provisioning')->index();
            $table->timestamps();
            // stancl/tenancy internal keys (e.g. tenancy_db_name).
            $table->json('data')->nullable();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
