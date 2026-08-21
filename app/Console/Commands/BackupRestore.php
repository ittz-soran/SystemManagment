<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Section 8b: "Test a restore before go-live and every few months after. An
 * untested backup is not a backup."
 *
 * So the restore is a command Soran can actually run, not a paragraph in a
 * runbook. It refuses without --force, because it overwrites everything.
 */
class BackupRestore extends Command
{
    protected $signature = 'backup:restore {file? : Path to a .sql.gz backup, or the newest one if omitted}
                            {--force : Required outside an interactive terminal}';

    protected $description = 'Restore the database from a backup file, replacing everything in it';

    public function handle(BackupService $backups): int
    {
        $file = $this->argument('file');

        if ($file === null) {
            $newest = $backups->copies('daily')[0] ?? null;

            if ($newest === null) {
                $this->error(__('There are no backups to restore.'));

                return self::FAILURE;
            }

            $file = $newest->getPathname();
        }

        $this->warn(__('This replaces every row in :database with the contents of the backup.', [
            'database' => config('database.connections.'.config('database.default').'.database'),
        ]));

        $this->line('  '.$file);

        if (! $this->option('force') && ! $this->confirm(__('Restore it?'), false)) {
            $this->line(__('Nothing was changed.'));

            return self::SUCCESS;
        }

        try {
            $backups->restore($file);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(__('Restored. Check the dashboard totals against what you expect before letting anyone sell.'));

        return self::SUCCESS;
    }
}
