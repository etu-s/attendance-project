<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Password reset tokens of super admins (the `super_admins` broker). Tenant
 * users keep theirs in each tenant's own password_reset_tokens table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('super_admin_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->collation('nocase')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('super_admin_password_reset_tokens');
    }
};
