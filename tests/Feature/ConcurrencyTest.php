<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\UsesItsOwnDatabase;

/**
 * Section 5 (Concurrency) and Section 11: two concurrent sales of the same
 * product, "run with real parallel requests, not sequential calls."
 *
 * This test does NOT use RefreshDatabase, because that wraps everything in one
 * outer transaction — the forked children would never see the fixture, and the
 * locking it is meant to prove would be invisible.
 *
 * IMPORTANT: lockForUpdate() is a silent no-op on SQLite, so this test is
 * skipped there. It only proves anything against MySQL/MariaDB.
 *
 * It is also skipped on Windows, where pcntl does not exist — which is most
 * shops. `php artisan stock:prove-locking` proves the same thing by starting
 * separate processes rather than forking, and runs anywhere.
 */
class ConcurrencyTest extends TestCase
{
    // It commits real transactions on purpose, and rebuilds the schema to do
    // it. Both are fine in a database nobody else is using.
    use UsesItsOwnDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                'Batch locking can only be proven against MySQL/MariaDB. '.
                'lockForUpdate() is a no-op on SQLite, so a pass here would be meaningless. '.
                'On the shop\'s own machine use the command instead — this test forks with '.
                'pcntl, which Windows does not have at all: '.
                'php artisan stock:prove-locking --database=store_locktest'
            );
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped(
                'pcntl is required to fork genuinely parallel requests, and it does not '.
                'exist on Windows. Use php artisan stock:prove-locking there, which starts '.
                'separate processes instead of forking.'
            );
        }
    }

    public function test_two_concurrent_sales_cannot_oversell_the_same_product(): void
    {

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::create(['name' => 'Test']);

        $product = Product::create([
            'name' => 'Contested', 'sku' => 'C1', 'category_id' => $category->id,
            'unit' => 'pcs', 'purchase_price' => 0, 'sale_price' => 10_000, 'quantity' => 0,
        ]);

        $supplier = Supplier::create(['name' => 'S']);
        $customer = Customer::create(['name' => 'C']);

        // Exactly 5 units in stock. Two sales each want 4.
        app(PurchaseService::class)->create(
            supplier: $supplier,
            lines: [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 1_000]],
            user: $user, purchaseDate: now(),
        );

        $pids = [];

        foreach (range(1, 2) as $i) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                // Child: its own connection, so the two really do race.
                DB::purge();

                $exitCode = 0;

                try {
                    app(SaleService::class)->create(
                        customer: $customer,
                        lines: [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 10_000]],
                        user: $user,
                        saleDate: now(),
                        amountPaid: 0,
                    );
                } catch (\Throwable) {
                    $exitCode = 1;   // Rejected, which is the correct outcome for one of them.
                }

                exit($exitCode);
            }

            $pids[] = $pid;
        }

        $succeeded = 0;

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);

            if (pcntl_wexitstatus($status) === 0) {
                $succeeded++;
            }
        }

        DB::purge();

        // Exactly one sale may win. Without the lock both read "5 available",
        // both consume 4, and stock ends at -3 with FIFO corrupted.
        $this->assertSame(1, $succeeded, 'Exactly one of the two concurrent sales should succeed');

        $remaining = (int) StockBatch::where('product_id', $product->id)->sum('quantity_remaining');

        $this->assertSame(1, $remaining, 'Stock must be 5 - 4 = 1, never negative');
        $this->assertSame($remaining, (int) Product::findOrFail($product->id)->quantity);
    }

    /**
     * A sale and a RETURN at the same moment, on the same product.
     *
     * ⚠️ **This is the case the test above cannot reach, and it was broken.**
     *
     * A sale takes the SALE document counter inside its own transaction, so two
     * tills serialise from their first statement and never contend on a batch at
     * all. A return takes the SALE_RETURN counter — a different row — so nothing
     * serialises the two. They then met on the same product and locked its rows
     * in opposite directions: `consume()` oldest batch first, `reverseMovements()`
     * newest first, and the `products` row itself claimed at different points by
     * each. Measured on MariaDB 10.11 on 2026-09-11, seven of twelve transactions
     * were killed:
     *
     *     SQLSTATE[40001]: Serialization failure: 1213 Deadlock found
     *
     * A deadlock corrupts nothing — InnoDB rolls its victim back whole — so every
     * assertion about stock, numbering and balances stayed true and the whole
     * suite stayed green. What the shopkeeper got was a sale failing with a 500,
     * at random, only when the shop was busy.
     *
     * So this asserts something the others do not: that nobody was killed. Both
     * sides must simply finish.
     */
    public function test_a_sale_and_a_return_at_once_do_not_deadlock(): void
    {
        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::create(['name' => 'Test']);

        $product = Product::create([
            'name' => 'Contested', 'sku' => 'C2', 'category_id' => $category->id,
            'unit' => 'pcs', 'purchase_price' => 0, 'sale_price' => 10_000, 'quantity' => 0,
        ]);

        $supplier = Supplier::create(['name' => 'S']);
        $customer = Customer::create(['name' => 'C']);

        // Four deliveries, so the product has four batch rows. One row cannot be
        // half of a cycle: the fault needs a transaction holding row A and
        // wanting row B while another holds B and wants A.
        foreach (range(1, 4) as $delivery) {
            app(PurchaseService::class)->create(
                supplier: $supplier,
                lines: [['product_id' => $product->id, 'quantity' => 25, 'unit_price' => 1_000 * $delivery]],
                user: $user,
                purchaseDate: now()->subDays(4 - $delivery),
            );
        }

        // A sale spanning several batches, for the returning side to give back.
        $sale = app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $product->id, 'quantity' => 60, 'unit_price' => 10_000]],
            user: $user, saleDate: now(), amountPaid: 0,
        );

        $pids = [];

        foreach (['sell', 'return'] as $role) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                DB::purge();

                $exitCode = 0;

                try {
                    for ($round = 0; $round < 12; $round++) {
                        if ($role === 'sell') {
                            app(SaleService::class)->create(
                                customer: $customer,
                                lines: [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10_000]],
                                user: $user, saleDate: now(), amountPaid: 0,
                            );

                            continue;
                        }

                        $item = $sale->items()->first()->fresh();
                        $left = (int) $item->quantity - (int) $item->quantity_returned;

                        if ($left <= 0) {
                            break;
                        }

                        app(SaleReturnService::class)->create(
                            sale: $sale->fresh(),
                            lines: [['sale_item_id' => $item->id, 'quantity' => min(2, $left)]],
                            user: $user,
                            returnDate: now(),
                        );
                    }
                } catch (\Throwable $e) {
                    // 2 means "the engine killed me to break a cycle", which is
                    // the only ending this test is looking for. Anything else is
                    // an ordinary refusal and says nothing either way.
                    $exitCode = str_contains($e->getMessage(), '40001')
                        || str_contains($e->getMessage(), 'Deadlock found')
                        || str_contains($e->getMessage(), 'Lock wait timeout') ? 2 : 1;
                }

                exit($exitCode);
            }

            $pids[] = $pid;
        }

        $deadlocked = 0;

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);

            if (pcntl_wexitstatus($status) === 2) {
                $deadlocked++;
            }
        }

        DB::purge();

        $this->assertSame(0, $deadlocked,
            'A sale and a return on the same product deadlocked. Every path must claim the '
            .'product and its batches in one order first — see FifoService::claim().');

        // And the shelf still adds up, which is what says the claim did not
        // simply serialise everything into doing nothing.
        $remaining = (int) StockBatch::where('product_id', $product->id)->sum('quantity_remaining');

        $this->assertSame($remaining, (int) Product::findOrFail($product->id)->quantity);
    }
}
