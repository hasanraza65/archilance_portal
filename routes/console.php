<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


Schedule::call(function () {
    app(\App\Console\Commands\CheckHeartBeat::class)->handle();
})->everyMinute();

// Daily digest of tasks with an approaching / overdue due date (each morning at 8am).
Schedule::command('notify:due-reminders')->dailyAt('08:00');