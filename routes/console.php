<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Section 8b: "Nightly mysqldump on a cron schedule." Runs in the shop's own
 * timezone, so "night" means night in Sulaymaniyah rather than on the server.
 *
 * This needs one line in the system crontab for the schedule to fire at all:
 *
 *     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
 */
Schedule::command('backup:run')
    ->dailyAt((string) config('backup.schedule', '02:15'))
    ->timezone(setting('timezone', config('app.timezone')))
    // A backup that overlaps the previous night's is a machine in trouble, not
    // a reason to start a second dump on top of it.
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('The nightly backup did not complete.'));
