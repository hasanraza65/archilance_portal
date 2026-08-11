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
// Schedule::call() + Artisan::call() so this runs in-process instead of spawning a
// new OS process/DB connection (see the note on the internee job below for why).
Schedule::call(function () {
    Artisan::call('notify:due-reminders');
})->dailyAt('08:00');

// Notify admins & executives when an employee's probation period end date is reached.
Schedule::call(function () {
    Artisan::call('notify:probation-reminders');
})->dailyAt('08:05');

// Auto-grade internees 0/0 for any working day they didn't work. Runs after
// midnight so "yesterday" is fully elapsed; it also backfills any older gaps.
//
// Uses Schedule::call() + Artisan::call() instead of Schedule::command() on
// purpose: command() shells out to a brand-new `php artisan ...` OS process,
// which has to open its own fresh DB connection from scratch — that's what
// was colliding with this host's max_user_connections cap. call() runs the
// command in-process instead, reusing the connection schedule:run itself
// already has open (same reason CheckHeartBeat above never has this problem).
Schedule::call(function () {
    Artisan::call('internee:auto-zero-grading');
})->dailyAt('01:30');