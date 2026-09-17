<?php

use App\Services\BackupService;
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
$backups = app(BackupService::class);

$backup = Schedule::command('backup:run')
    ->timezone(setting('timezone', config('app.timezone')))
    // A backup that overlaps the previous night's is a machine in trouble, not
    // a reason to start a second dump on top of it.
    ->withoutOverlapping()
    ->onFailure(fn () => Log::error('The scheduled backup did not complete.'));

// Section 8c: the frequency is an admin setting, so this is read from the
// database rather than fixed here.
$backups->isWeekly()
    ? $backup->weeklyOn($backups->scheduledWeekday(), $backups->scheduledTime())
    : $backup->dailyAt($backups->scheduledTime());

/*
 * Buzzing the phones that asked — Soran, 2026-09-17.
 *
 * ⚠️ On the cron a shop ALREADY has. Backups needed one crontab line and this
 * rides on the same one, so a shopkeeper has nothing new to set up:
 *
 *     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
 *
 * Every minute, because "somebody signed into your account" is worth a minute
 * and not worth five. It costs one indexed query when nothing has happened, and
 * returns immediately when the shop has no keys or no phones.
 *
 * withoutOverlapping, because a push service being slow must not let two runs
 * read the same watermark and send the same buzz twice.
 */
Schedule::command('push:send')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
