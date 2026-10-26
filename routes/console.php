<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// R12: daily "Due tomorrow" / "Overdue" reminders (idempotent; see the command).
Schedule::command('academy:send-reminders')->dailyAt('08:00');
