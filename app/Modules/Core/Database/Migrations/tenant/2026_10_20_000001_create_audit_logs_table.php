<?php

declare(strict_types=1);

use App\Modules\Core\Database\AuditLogSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AuditLogSchema::create();
    }

    public function down(): void
    {
        AuditLogSchema::drop();
    }
};
