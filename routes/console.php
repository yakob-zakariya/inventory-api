<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly, in server time (UTC). The server's cron runs `php artisan schedule:run` every minute.
Schedule::command('backup:clean')->dailyAt('23:00');
Schedule::command('backup:run')->dailyAt('23:30');
Schedule::command('backup:monitor')->dailyAt('00:30');

Schedule::command('inventory:verify')->dailyAt('01:00')
    ->emailOutputOnFailure(config('backup.notifications.mail.to'));
