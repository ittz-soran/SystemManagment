<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockRoom;
use App\Models\User;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Putting the FIFO order back — Soran's cable, 2026-09-24.
 *
 * ⚠️ **His actual shop, rebuilt.** Two batches of one cable: 29 in on 24
 * August at 2,475, and 9 more on 6 September at 2,500. MySQL had been
 * rewriting `stock_batches.received_at` on every update to the row, so by the
 * 23rd the older batch looked NEWER than the other one and a sale reached past
 * 29 units for the dearer layer.
 *
 * The repair reads each batch's creating movement, which kept the truth
 * because movements are inserted and deleted but never updated.
 */
class RepairBatchDatesTest extends TestCase
{
    use RefreshDatabase;

    private Product $cable;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cable = Product::create([
            'name' => 'Cable Sikenai 2A A to LTG', 'kind' => Product::KIND_STOCK,
            'sku' => 'SX-7L', 'barcode' => 'SX-7L-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 2_475, 'sale_price' => 4_000, 'quantity' => 0,
        ]);

        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        app(StockAdjustmentService::class)->recordOpeningStock(
            product: $this->cable, quantity: 29, unitCost: 2_475,
            user: User::first(), adjustedAt: now()->subDays(31),
        );

        app(StockAdjustmentService::class)->recordOpeningStock(
            product: $this->cable->refresh(), quantity: 9, unitCost: 2_500,
            user: User::first(), adjustedAt: now()->subDays(18),
        );
    }

    private function old(): StockBatch
    {
        return StockBatch::where('unit_cost', 2_475)->firstOrFail();
    }

    private function new(): StockBatch
    {
        return StockBatch::where('unit_cost', 2_500)->firstOrFail();
    }

    /** What MySQL did: stamped the row with the moment it was last written. */
    private function corrupt(StockBatch $batch, string $when): void
    {
        DB::table('stock_batches')->where('id', $batch->id)->update(['received_at' => $when]);
    }

    private function sell(): void
    {
        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->cable->id, 'quantity' => 1, 'unit_price' => 4_000]],
            user: User::first(), saleDate: now(), amountPaid: 4_000, paymentMethod: 'cash',
        );
    }

    /** ⚠️ With the dates corrupted, the till really does take the wrong batch. */
    public function test_the_corruption_reproduces_the_wrong_batch(): void
    {
        $this->corrupt($this->old(), now()->toDateTimeString());

        $this->sell();

        $this->assertSame(9 - 1, $this->new()->fresh()->quantity_remaining,
            'the fixture does not reproduce the bug, so the repair below proves nothing');
        $this->assertSame(29, $this->old()->fresh()->quantity_remaining);
    }

    /** And the repair puts it back, from the movement that kept the truth. */
    public function test_the_repair_restores_the_date_and_the_order(): void
    {
        $bornOld = $this->old()->received_at->copy();
        $bornNew = $this->new()->received_at->copy();

        $this->corrupt($this->old(), now()->toDateTimeString());
        $this->corrupt($this->new(), now()->toDateTimeString());

        $this->artisan('stock:repair-batch-dates')
            ->expectsOutputToContain('Repaired 2 batch date(s).')
            ->assertSuccessful();

        $this->assertTrue($bornOld->equalTo($this->old()->fresh()->received_at));
        $this->assertTrue($bornNew->equalTo($this->new()->fresh()->received_at));

        // And the till reaches for the older layer again.
        $this->sell();

        $this->assertSame(28, $this->old()->fresh()->quantity_remaining);
        $this->assertSame(9, $this->new()->fresh()->quantity_remaining);
    }

    /**
     * ⚠️ **The CREATING movement, not the latest one.**
     *
     * A batch gathers more positive movements over its life — every sale
     * return puts units back into the batch they came from. The date to
     * restore is the one the batch was born with, and reading the newest
     * instead would age the batch forward to the last time anything came back
     * into it: the same bug, rebuilt by the thing meant to cure it.
     *
     * The other tests here cannot see the difference, because a batch with one
     * movement has the same oldest and newest. This one failed a sabotage that
     * they all walked through.
     */
    public function test_the_repair_reads_the_movement_that_created_the_batch(): void
    {
        $born = $this->old()->received_at->copy();

        // Sell one and take it back, which writes a second positive movement
        // into that batch, dated today.
        $this->sell();

        $sale = Sale::latest('id')->firstOrFail();

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            user: User::first(),
            returnDate: now(),
        );

        $this->assertSame(2, StockMovement::where('stock_batch_id', $this->old()->id)
            ->where('quantity', '>', 0)->count(), 'the fixture has only one inbound movement');

        $this->corrupt($this->old(), now()->toDateTimeString());

        $this->artisan('stock:repair-batch-dates')->assertSuccessful();

        $this->assertTrue(
            $born->equalTo($this->old()->fresh()->received_at),
            'the batch was aged forward to the day something came back into it',
        );
    }

    /**
     * ⚠️ Transferred batches keep the age they were given.
     *
     * TransferService carries the SOURCE batch's `received_at` on purpose, so
     * stock moved between rooms does not jump to the front of the FIFO queue.
     * Its own inbound movement is dated the transfer, so repairing from that
     * would make old stock look new.
     */
    public function test_a_transferred_batch_is_left_alone(): void
    {
        $carried = StockBatch::create([
            'product_id' => $this->cable->id,
            'room_id' => StockRoom::main()->id,
            'source_type' => 'transfer',
            'source_id' => 1,
            'unit_cost' => 2_475,
            'quantity_in' => 1,
            'quantity_remaining' => 1,
            // The age it was given: old, because the stock is old.
            'received_at' => now()->subDays(31),
            'sequence' => 1,
        ]);

        StockMovement::create([
            'product_id' => $this->cable->id,
            'stock_batch_id' => $carried->id,
            'reference_type' => 'transfer',
            'reference_id' => 1,
            'quantity' => 1,
            'unit_cost' => 2_475,
            // Carried today, which is NOT when the stock arrived in the shop.
            'occurred_at' => now(),
            'sequence' => 1,
            'user_id' => User::first()->id,
        ]);

        $carriedAge = $carried->fresh()->received_at->copy();

        $this->artisan('stock:repair-batch-dates')
            ->expectsOutputToContain('1 transferred batch(es) left alone')
            ->assertSuccessful();

        $this->assertTrue(
            $carriedAge->equalTo($carried->fresh()->received_at),
            'a transferred batch was aged forward to the day it was carried',
        );
    }

    /** Running it twice is not a second repair. */
    public function test_it_is_safe_to_run_again(): void
    {
        $this->corrupt($this->old(), now()->toDateTimeString());

        $this->artisan('stock:repair-batch-dates')->assertSuccessful();

        $this->artisan('stock:repair-batch-dates')
            ->expectsOutputToContain('Every batch already carries the date its own movement says.')
            ->assertSuccessful();
    }

    /** ⚠️ And --pretend changes nothing, which is the point of it. */
    public function test_pretend_reports_without_writing(): void
    {
        $this->corrupt($this->old(), now()->toDateTimeString());

        $wrong = $this->old()->fresh()->received_at->copy();

        $this->artisan('stock:repair-batch-dates --pretend')
            ->expectsOutputToContain('Would repair 1 batch date(s).')
            ->assertSuccessful();

        $this->assertTrue($wrong->equalTo($this->old()->fresh()->received_at));
    }
}
