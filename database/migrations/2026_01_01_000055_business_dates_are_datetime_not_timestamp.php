<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every business date becomes DATETIME, and the FIFO order is repaired.
 *
 * ⚠️ **Soran found this in his own shop — 2026-09-24.** He swapped a cable and
 * the replacement came off the NEWER batch while 29 units sat in the older one.
 * *"this Sale INV-00054 #345 line must user old batch are 128 ... and this
 * 2026-09-24 10:26 date times is wrong!!"* — both batches were showing the same
 * timestamp, minutes old, while their own movements said August and September.
 *
 * ⚠️ **MySQL and MariaDB were rewriting the column on every UPDATE.** The first
 * `TIMESTAMP` column in a table that is NOT NULL and carries no explicit
 * default is silently given `DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP` — the rule is decades old and it is on by default in
 * MariaDB. Laravel's `$table->timestamp('received_at', 6)` emits exactly that
 * column, as `create table ... `received_at` timestamp(6) not null` — checked
 * by compiling the blueprint against the MySQL grammar, not assumed.
 *
 * So every sale, return, swap and adjustment that touched a batch's
 * `quantity_remaining` also reset its `received_at` to the moment of the touch.
 * `StockBatch::scopeFifoOrder` sorts by that column. **FIFO had quietly become
 * "least recently touched first" instead of "oldest first"** — the wrong cost
 * on the wrong sale, in the one calculation this shop is judged by.
 *
 * ⚠️ **Not one test could see it.** The suite runs on SQLite, where `timestamp`
 * is a column like any other and nothing rewrites it. The CI matrix does run
 * MariaDB — but nothing asserted that a date stays put when a row is updated,
 * so it passed there too. Both guards are added with this migration.
 *
 * `DATETIME` has none of that behaviour, no timezone conversion on the way in
 * or out, and no 2038 limit. It is what a business date should always have
 * been. Every such column in the shop changes, not only the one that bit:
 * `repairs.received_at` is rewritten by every status change, `swaps.swapped_at`
 * by the service's own second save, `payments.paid_at` and
 * `stock_adjustments.adjusted_at` by any edit.
 */
return new class extends Migration
{
    /**
     * Every business date in the shop: table => [column => precision].
     *
     * `jobs.failed_at` is Laravel's own and already carries `useCurrent()`,
     * which exempts it from the rule.
     */
    private const DATES = [
        'stock_batches' => ['received_at' => 6],
        'stock_movements' => ['occurred_at' => 6],
        'stock_transfers' => ['transferred_at' => 6],
        'repair_approvals' => ['approved_at' => 6],
        'repairs' => ['received_at' => 6],
        'swaps' => ['swapped_at' => 6],
        'stock_adjustments' => ['adjusted_at' => 0],
        'payments' => ['paid_at' => 0],
    ];

    public function up(): void
    {
        foreach (self::DATES as $table => $columns) {
            foreach ($columns as $column => $precision) {
                Schema::table($table, function (Blueprint $blueprint) use ($column, $precision) {
                    /*
                     * ⚠️ Every attribute restated. A `change()` describes the
                     * column it wants in full — anything left out is dropped,
                     * so an omitted precision would quietly round every FIFO
                     * timestamp to the second and make ties out of orders that
                     * were distinct.
                     */
                    $blueprint->dateTime($column, $precision)->nullable(false)->change();
                });
            }
        }

        $this->repairBatchDates();
    }

    /**
     * Put back the dates MySQL overwrote, from the movements that kept them.
     *
     * ⚠️ **The truth was never lost, only misfiled.** A batch is created with a
     * movement in the same breath and from the same value, and movements are
     * inserted and deleted but never updated — so the auto-update never touched
     * them. Soran's own screenshots show it: batch #128 reading 2026-09-24
     * 10:26 while its `+29 pcs` movement still reads 2026-08-24 14:46.
     *
     * ⚠️ **Transferred batches are left alone.** TransferService deliberately
     * carries the SOURCE batch's `received_at` so that stock moved between
     * rooms keeps its age — its own inbound movement is dated the transfer, and
     * "repairing" from that would make old stock look new, which is the same
     * bug pointing the other way.
     */
    private function repairBatchDates(): void
    {
        $repaired = 0;

        DB::table('stock_batches')
            ->whereIn('source_type', ['purchase', 'adjustment'])
            ->orderBy('id')
            ->chunkById(500, function ($batches) use (&$repaired) {
                foreach ($batches as $batch) {
                    $born = DB::table('stock_movements')
                        ->where('stock_batch_id', $batch->id)
                        ->where('quantity', '>', 0)
                        ->orderBy('occurred_at')
                        ->orderBy('id')
                        ->value('occurred_at');

                    if ($born === null || $born === $batch->received_at) {
                        continue;
                    }

                    DB::table('stock_batches')->where('id', $batch->id)
                        ->update(['received_at' => $born]);

                    $repaired++;
                }
            });

        if ($repaired > 0) {
            // Worth saying out loud during a deploy: it is the shop's FIFO
            // order being put back, not a routine schema tidy.
            echo "  Repaired {$repaired} batch date(s) that MySQL had overwritten.\n";
        }
    }

    public function down(): void
    {
        foreach (self::DATES as $table => $columns) {
            foreach ($columns as $column => $precision) {
                Schema::table($table, function (Blueprint $blueprint) use ($column, $precision) {
                    $blueprint->timestamp($column, $precision)->nullable(false)->change();
                });
            }
        }
    }
};
