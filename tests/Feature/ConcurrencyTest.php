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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

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
        $this->artisan('migrate:fresh --seed');

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
}
