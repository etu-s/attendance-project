<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Turns finished working days without punches into absent / incomplete (Attendance module).
Schedule::command('attendance:close-days')->everyFifteenMinutes()->withoutOverlapping();

// Leave grants, monthly accruals, carry-forward and expiry (Leave module); idempotent.
Schedule::command('leave:accrue')->hourly()->withoutOverlapping();

// Deletes report export files past their expiry (Reports module).
Schedule::command('reports:prune-exports')->daily()->withoutOverlapping();
