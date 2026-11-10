<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * An optional label for a break ("Lunch", "Dinner"). Display only: it is not
 * a time rule, so it can change on a timetable that is in use.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Local databases built while this column was briefly part of the
        // create migration already have it.
        if (Schema::hasColumn('timetable_breaks', 'name')) {
            return;
        }

        Schema::table('timetable_breaks', function (Blueprint $table): void {
            $table->string('name', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('timetable_breaks', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }
};
