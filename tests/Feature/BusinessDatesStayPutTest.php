<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A date written on a document stays where it was put — Soran, 2026-09-24.
 *
 * ⚠️ **He found this in his own shop.** He swapped a cable and the replacement
 * came off the NEWER batch while 29 units sat in the older one; both batches
 * were showing the same timestamp, minutes old, while their own movements still
 * said August and September.
 *
 * ⚠️ **MySQL and MariaDB were rewriting the column on every UPDATE.** The first
 * `TIMESTAMP` column in a table that is NOT NULL and carries no explicit
 * default is silently given `DEFAULT CURRENT_TIMESTAMP ON UPDATE
 * CURRENT_TIMESTAMP`, and that is on by default in MariaDB. `stock_batches`
 * had exactly such a column, and `StockBatch::scopeFifoOrder` sorts by it — so
 * every sale that changed `quantity_remaining` re-aged the batch it sold from,
 * and FIFO quietly became "least recently touched first".
 *
 * ⚠️ **Nothing in this suite could see it**, because it runs on SQLite where
 * `timestamp` is a column like any other. The CI matrix runs MariaDB too, and
 * it passed there as well — because no test had ever asked whether a date
 * survives an update. This one asks, on whatever driver it is given.
 */
class BusinessDatesStayPutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The columns a shopkeeper would swear to, and the shop is judged by.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function businessDates(): array
    {
        return [
            'a batch was received' => ['stock_batches', 'received_at'],
            'a movement happened' => ['stock_movements', 'occurred_at'],
            'stock was moved' => ['stock_transfers', 'transferred_at'],
            'a job came in' => ['repairs', 'received_at'],
            'a customer agreed' => ['repair_approvals', 'approved_at'],
            'a faulty item was swapped' => ['swaps', 'swapped_at'],
            'the shelf was corrected' => ['stock_adjustments', 'adjusted_at'],
            'money changed hands' => ['payments', 'paid_at'],
        ];
    }

    /**
     * ⚠️ **Verified to discriminate, not assumed to.** This container cannot
     * run MariaDB, so the auto-update was reproduced here with a SQLite trigger
     * that rewrites `received_at` after every update to the row — a throwaway
     * subclass of this test, run once. With the trigger in place this method
     * fails on its first assertion, with the message below; without it, it
     * passes. That is the same failure CI will show on MariaDB if the column
     * ever goes back to being a TIMESTAMP.
     *
     * ⚠️ And the real thing, end to end: a sale must not re-age the batch it
     * sells from. This is the shape of what Soran actually saw.
     */
    public function test_selling_from_a_batch_does_not_make_it_look_new(): void
    {
        $this->seed();
        $user = User::first();

        $product = Product::create([
            'name' => 'Cable Sikenai 2A', 'kind' => Product::KIND_STOCK, 'sku' => 'SX-7L',
            'barcode' => 'SX-7L-B', 'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 2_475, 'sale_price' => 4_000, 'quantity' => 0,
        ]);
        $supplier = Supplier::create(['name' => 'Bazaar', 'phone' => '0770', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        // The old, big batch, and a newer, smaller, dearer one.
        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $product->id, 'quantity' => 29, 'unit_price' => 2_475]],
            user: $user, purchaseDate: now()->subDays(31), amountPaid: 71_775,
        );
        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $product->id, 'quantity' => 9, 'unit_price' => 2_500]],
            user: $user, purchaseDate: now()->subDays(18), amountPaid: 22_500,
        );

        $old = StockBatch::where('unit_cost', 2_475)->firstOrFail();
        $new = StockBatch::where('unit_cost', 2_500)->firstOrFail();

        $bornOld = $old->received_at->copy();

        // Sell one. FIFO must take the old batch, and must not re-age it.
        $sell = fn () => app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 4_000]],
            user: $user, saleDate: now(), amountPaid: 4_000, paymentMethod: 'cash',
        );

        $sell();

        $this->assertSame(28, $old->fresh()->quantity_remaining, 'FIFO took the wrong batch');
        $this->assertTrue(
            $bornOld->equalTo($old->fresh()->received_at),
            'selling from a batch rewrote the day it arrived, so FIFO will take the wrong one next time',
        );

        // ⚠️ And again — this is the sale that went wrong in Soran's shop. With
        // the date rewritten, the older batch now looks newer than the other
        // one and FIFO reaches past 28 remaining units for the dearer layer.
        $sell();

        $this->assertSame(27, $old->fresh()->quantity_remaining, 'FIFO reached past the older batch');
        $this->assertSame(9, $new->fresh()->quantity_remaining, 'the newer batch was sold from first');
    }

    /** Whatever the driver, the column is not one MySQL will auto-update. */
    public function test_no_business_date_is_a_timestamp_column_on_mysql(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The auto-update rule is MySQL and MariaDB only.');
        }

        foreach (self::businessDates() as [$table, $column]) {
            $type = collect(Schema::getColumns($table))->firstWhere('name', $column)['type_name'] ?? null;

            $this->assertSame('datetime', $type,
                "{$table}.{$column} is a {$type}; MySQL gives the first such column ON UPDATE CURRENT_TIMESTAMP");
        }
    }
}
