<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Section 8b: the nightly dump, scheduled in routes/console.php.
 *
 * The "Back up now" button calls BackupService directly so it can attribute the
 * run to whoever pressed it; a scheduled run has no user, which is why nothing
 * is passed here.
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Dump the database, copy it off the machine, and prune old copies';

    public function handle(BackupService $backups): int
    {
        $this->info(__('Backing up…'));

        try {
            $result = $backups->run();
        } catch (Throwable $e) {
            // A backup that fails quietly is worse than no backup, because the
            // settings page would keep showing the last successful run.
            Log::error('Backup failed: '.$e->getMessage());

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('  '.$result['path'].'  ('.$backups->humanSize($result['bytes']).')');

        if ($result['remote']) {
            $this->line('  '.__('Copied off the machine to :path', ['path' => $result['remote']]));
        }

        foreach ($result['warnings'] as $warning) {
            $this->warn('  '.$warning);
            Log::warning('Backup: '.$warning);
        }

        return self::SUCCESS;
    }
}
