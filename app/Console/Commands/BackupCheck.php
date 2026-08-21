<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * "Is the backup actually working?" — answered in one command.
 *
 * Every failure in this area is indirect: a tool that is not on PATH, a folder
 * the web account cannot write to, a socket error that is really a missing
 * environment variable. Reading an exception and guessing is no way to find out
 * whether the shop's records are safe.
 */
class BackupCheck extends Command
{
    protected $signature = 'backup:check';

    protected $description = 'Check that backups can actually run, and say what to fix if not';

    public function handle(BackupService $backups): int
    {
        $this->newLine();

        $failed = [];

        foreach ($backups->diagnose() as $check) {
            $this->line(sprintf(
                ' %s  %s  <fg=gray>%s</>',
                $check['ok'] ? '<fg=green>OK  </>' : '<fg=red>FAIL</>',
                str_pad($check['name'], 24),
                $check['detail'],
            ));

            if (! $check['ok']) {
                $failed[] = $check;
            }
        }

        $this->newLine();

        if ($failed === []) {
            $this->info(__('Backups are set up correctly.'));

            return self::SUCCESS;
        }

        foreach ($failed as $check) {
            if ($check['fix']) {
                $this->warn($check['name'].': '.$check['fix']);
                $this->newLine();
            }
        }

        return self::FAILURE;
    }
}
