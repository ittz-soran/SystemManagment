<?php

namespace App\Console\Commands;

use App\Services\PushSender;
use Illuminate\Console\Command;

/**
 * Send what has happened since the last minute to the phones that asked.
 *
 * Run by the cron that already runs `schedule:run` — see routes/console.php.
 * Nothing extra to set up beyond the line a shop already has for backups.
 */
class PushSend extends Command
{
    protected $signature = 'push:send';

    protected $description = 'Send new shop activity to the phones that asked for it';

    public function handle(PushSender $sender): int
    {
        if (! $sender->configured()) {
            $this->line('No keys set, so nothing is sent. Run: php artisan push:keys');

            return self::SUCCESS;
        }

        $tally = $sender->run();

        $this->line(sprintf(
            'sent %d to %d device(s), %d failed, %d skipped',
            $tally['sent'], $tally['devices'], $tally['failed'], $tally['skipped'],
        ));

        return self::SUCCESS;
    }
}
