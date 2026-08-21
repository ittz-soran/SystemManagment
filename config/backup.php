<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Where backups are written
    |---------------------------------------------------------------------------
    |
    | Section 8b: "Nightly mysqldump on a cron schedule, kept off the machine
    | running the app — a dead disk should not take both."
    |
    | `local` is the working directory the dump is written to first. `remote` is
    | the off-machine copy, and it is the one that matters: a mounted share, an
    | external drive, or a directory an rsync/rclone job picks up. Leaving it
    | unset makes `backup:run` warn on every run, because a backup that lives on
    | the same disk as the database is not a backup.
    |
    */

    'local' => env('BACKUP_PATH', storage_path('app/backups')),

    'remote' => env('BACKUP_REMOTE_PATH'),

    /*
    |---------------------------------------------------------------------------
    | Retention
    |---------------------------------------------------------------------------
    |
    | Section 8b: "Retain 30 daily and 12 monthly copies." The first backup of
    | each calendar month is also kept as that month's monthly copy, so a year
    | of month-ends survives even though dailies roll off after a month.
    |
    */

    'keep_daily' => (int) env('BACKUP_KEEP_DAILY', 30),

    'keep_monthly' => (int) env('BACKUP_KEEP_MONTHLY', 12),

    /*
    |---------------------------------------------------------------------------
    | Schedule
    |---------------------------------------------------------------------------
    |
    | The nightly run, in the shop's timezone. Quiet enough that nobody is
    | mid-sale, early enough that a failure is noticed the same morning.
    |
    */

    'schedule' => env('BACKUP_AT', '02:15'),

    /*
    |---------------------------------------------------------------------------
    | Tools
    |---------------------------------------------------------------------------
    |
    | Absolute paths if these are not on the web user's PATH — cron's PATH is
    | usually much shorter than a login shell's, which is the classic reason a
    | backup works by hand and silently fails at night.
    |
    */

    'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),

    'mysql' => env('MYSQL_PATH', 'mysql'),

    'timeout' => (int) env('BACKUP_TIMEOUT', 900),

];
