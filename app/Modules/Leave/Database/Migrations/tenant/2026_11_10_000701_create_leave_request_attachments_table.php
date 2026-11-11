<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Supporting documents of a leave request (e.g. a medical certificate):
 * PDF, JPG or PNG, at most 5 MB each and 3 per request. Files live on the
 * tenant's local disk; `path` is relative to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->string('original_name', 150);
            $table->string('mime_type', 50);
            $table->unsignedInteger('size_bytes');
            $table->string('path', 255);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('created_at');

            $table->index('leave_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_attachments');
    }
};
