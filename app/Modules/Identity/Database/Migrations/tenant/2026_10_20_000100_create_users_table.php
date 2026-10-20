<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            // NOCASE: Jane@x.com and jane@x.com are the same login. The unique
            // index includes soft-deleted rows, so a deleted user's email stays reserved.
            $table->string('email')->collation('nocase')->unique();
            $table->string('password');
            $table->string('status', 20)->default('active')->index();
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index('name');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->collation('nocase')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
