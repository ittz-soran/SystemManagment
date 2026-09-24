<?php

use App\Console\Commands\StockRepairBatchDates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
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
     * ⚠️ The arithmetic lives in `stock:repair-batch-dates` rather than here,
     * so it can be run again on a shop whose FIFO order is already wrong, read
     * out loud with `--pretend`, and — the part that matters — tested. A
     * migration runs once and is never looked at again.
     *
     * ⚠️ **And it is called SILENTLY.** A first version handed it a real
     * console and printed what it had repaired, which read well by hand and
     * broke six tests: `shop:update` runs migrations and prints JSON, and a
     * migration writing to stdout corrupts it. The shopkeeper's own update
     * would have failed on the deploy this migration exists for. Run the
     * command by hand to see the detail.
     */
    private function repairBatchDates(): void
    {
        Artisan::call(StockRepairBatchDates::class);
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
