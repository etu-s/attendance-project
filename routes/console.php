<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Turns finished working days without punches into absent / incomplete (Attendance module).
Schedule::command('attendance:close-days')->everyFifteenMinutes()->withoutOverlapping();
