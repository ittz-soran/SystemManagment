<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Put back the arrival dates MySQL overwrote — Soran, 2026-09-24.
 *
 * ⚠️ **Why this exists as a command and not only inside its migration.** The
 * migration runs once, on a deploy, on a shop whose FIFO order is already
 * wrong; if anything about that run is doubted, the answer has to be something
 * that can be run again and read out loud. It is also the only part of that
 * migration with arithmetic in it, and arithmetic that touches stock costs is
 * worth a test of its own.
 *
 * ⚠️ **The truth was never lost, only misfiled.** A batch is created with a
 * movement in the same breath and from the same value, and movements are
 * inserted and deleted but never updated — so MySQL's `ON UPDATE
 * CURRENT_TIMESTAMP` never touched them. Soran's own screenshots showed it:
 * batch #128 reading 2026-09-24 10:26 while its `+29 pcs` movement still read
 * 2026-08-24 14:46.
 *
 * ⚠️ **Transferred batches are left alone.** TransferService deliberately
 * carries the SOURCE batch's `received_at` so stock moved between rooms keeps
 * its age. Its own inbound movement is dated the transfer, so "repairing" from
 * that would make old stock look new — the same bug pointing the other way.
 *
 * Safe to run twice: a batch whose date already matches its movement is left
 * untouched, so a second run reports nothing to do.
 */
class StockRepairBatchDates extends Command
{
    protected $signature = 'stock:repair-batch-dates {--pretend : List what would change without changing it}';

    protected $description = 'Restore each stock batch\'s arrival date from the movement that created it';

    public function handle(): int
    {
        $pretend = (bool) $this->option('pretend');
        $repaired = 0;
        $skipped = 0;

        DB::table('stock_batches')
            ->orderBy('id')
            ->chunkById(500, function ($batches) use (&$repaired, &$skipped, $pretend) {
                foreach ($batches as $batch) {
                    if (! in_array($batch->source_type, ['purchase', 'adjustment'], true)) {
                        $skipped++;

                        continue;
                    }

                    $born = DB::table('stock_movements')
                        ->where('stock_batch_id', $batch->id)
                        ->where('quantity', '>', 0)
                        ->orderBy('occurred_at')
                        ->orderBy('id')
                        ->value('occurred_at');

                    if ($born === null || $this->same($born, $batch->received_at)) {
                        continue;
                    }

                    $this->line(sprintf(
                        '  batch #%d: %s → %s',
                        $batch->id,
                        $batch->received_at,
                        $born,
                    ));

                    if (! $pretend) {
                        DB::table('stock_batches')->where('id', $batch->id)
                            ->update(['received_at' => $born]);
                    }

                    $repaired++;
                }
            });

        if ($repaired === 0) {
            $this->info('Every batch already carries the date its own movement says. Nothing to do.');
        } else {
            $this->info(($pretend ? 'Would repair ' : 'Repaired ').$repaired.' batch date(s).');
        }

        if ($skipped > 0) {
            $this->line($skipped.' transferred batch(es) left alone — those carry their source\'s age on purpose.');
        }

        return self::SUCCESS;
    }

    /** Both sides may be strings or datetimes, and the precision may differ. */
    private function same(mixed $a, mixed $b): bool
    {
        return Carbon::parse($a)->equalTo(Carbon::parse($b));
    }
}
