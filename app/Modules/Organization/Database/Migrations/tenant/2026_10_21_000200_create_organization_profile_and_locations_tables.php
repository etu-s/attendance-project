<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Exactly one row (id = 1), managed by OrganizationProfileService.
        Schema::create('organization_profile', function (Blueprint $table): void {
            $table->id();
            $table->string('legal_name', 160);
            $table->string('display_name', 120)->nullable();
            $table->string('registration_number', 60)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 30)->collation('nocase')->unique();
            $table->text('address')->nullable();
            // Null = use the tenant timezone (general.timezone).
            $table->string('timezone', 64)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('organization_profile');
    }
};
