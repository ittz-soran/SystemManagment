<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 5: "When stock is sold, the units purchased first are consumed first,
 * at that batch's cost." Section 4: "Order FIFO by received_at, sequence —
 * never by id."
 *
 * The acceptance test proves this for two batches. This proves the ordering
 * rule itself, including the cases where id order and FIFO order disagree.
 */
class FifoOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::create(['name' => 'Test']);

        $this->product = Product::create([
            'name' => 'Widget', 'sku' => 'W1', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 0, 'sale_price' => 50_000, 'quantity' => 0,
        ]);

        $this->customer = Customer::create(['name' => 'C']);
    }

    /** Three batches at three costs: the oldest empties before the next is touched. */
    public function test_a_sale_consumes_the_oldest_batch_first(): void
    {
        $this->buy(3, 10_000, now()->subDays(3));
        $this->buy(3, 20_000, now()->subDays(2));
        $this->buy(3, 30_000, now()->subDay());

        // Take 7 of the 9: all of the first, all of the second, one of the third.
        $sale = $this->sell(7);

        $movements = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)
            ->orderBy('sequence')
            ->get();

        $this->assertSame(
            [[3, 10_000], [3, 20_000], [1, 30_000]],
            $movements->map(fn ($m) => [abs($m->quantity), $m->unit_cost])->all(),
            'Consumed oldest-first, at each batch\'s own cost',
        );

        // COGS is 3x10,000 + 3x20,000 + 1x30,000 = 120,000 — not an average.
        $cogs = $movements->sum(fn ($m) => abs($m->quantity) * $m->unit_cost);
        $this->assertSame(120_000, $cogs);

        // An average would have given 7 x 20,000 = 140,000.
        $this->assertNotSame(140_000, $cogs, 'FIFO, not average cost');

        $batches = StockBatch::fifoOrder()->get();
        $this->assertSame([0, 0, 2], $batches->pluck('quantity_remaining')->all());
    }

    /**
     * Section 4's reason for the `sequence` column: the same product twice in
     * one purchase shares a timestamp, so without it the order is undefined.
     */
    public function test_two_lines_in_one_purchase_are_ordered_by_sequence(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S']),
            lines: [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 11_000],
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 12_000],
            ],
            user: $this->user, purchaseDate: now(),
        );

        $batches = StockBatch::where('source_id', $purchase->id)->fifoOrder()->get();

        $this->assertSame(
            $batches[0]->received_at->format('Y-m-d H:i:s.u'),
            $batches[1]->received_at->format('Y-m-d H:i:s.u'),
            'Both lines of one purchase share a timestamp, which is the whole point',
        );

        $this->assertLessThan($batches[1]->sequence, $batches[0]->sequence);

        // 3 units: both of line 1 at 11,000, then one of line 2 at 12,000.
        $sale = $this->sell(3);

        $costs = StockMovement::where('reference_id', $sale->id)
            ->where('reference_type', StockMovement::REF_SALE)
            ->orderBy('sequence')
            ->get()
            ->map(fn ($m) => [abs($m->quantity), $m->unit_cost])
            ->all();

        $this->assertSame([[2, 11_000], [1, 12_000]], $costs);
    }

    /**
     * The case where id order and FIFO order disagree: a purchase entered later
     * but back-dated earlier is older stock, so it must be consumed first.
     *
     * This is why Section 4 says to order by received_at and never by id.
     */
    public function test_a_back_dated_purchase_is_consumed_before_an_earlier_entered_one(): void
    {
        // Entered first, dated today.
        $today = $this->buy(2, 30_000, now());

        // Entered second — so a higher id — but the goods arrived a week ago.
        $backDated = $this->buy(2, 10_000, now()->subWeek());

        $this->assertGreaterThan($today->id, $backDated->id, 'Higher id');

        $sale = $this->sell(2);

        $movement = StockMovement::where('reference_id', $sale->id)
            ->where('reference_type', StockMovement::REF_SALE)
            ->sole();

        $this->assertSame(
            $backDated->id,
            $movement->stock_batch_id,
            'The older stock is consumed first, even though it was entered later',
        );
        $this->assertSame(10_000, $movement->unit_cost);

        $this->assertSame(0, $backDated->refresh()->quantity_remaining);
        $this->assertSame(2, $today->refresh()->quantity_remaining, 'Untouched');
    }

    /** A batch emptied and then refilled by a return rejoins FIFO in its old place. */
    public function test_a_refilled_batch_keeps_its_original_fifo_position(): void
    {
        $old = $this->buy(2, 10_000, now()->subDays(2));
        $new = $this->buy(2, 20_000, now()->subDay());

        // Empty the old batch.
        $sale = $this->sell(2);
        $this->assertSame(0, $old->refresh()->quantity_remaining);

        // Send both back. Section 5: an empty batch is never closed.
        app(\App\Services\SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->user, returnDate: now(),
        );

        $this->assertSame(2, $old->refresh()->quantity_remaining, 'Refilled');

        // The next sale must draw from the OLD batch again, not the newer one.
        $second = $this->sell(1);

        $movement = StockMovement::where('reference_id', $second->id)
            ->where('reference_type', StockMovement::REF_SALE)
            ->sole();

        $this->assertSame($old->id, $movement->stock_batch_id);
        $this->assertSame(10_000, $movement->unit_cost, 'Still the old batch\'s cost');
        $this->assertSame(2, $new->refresh()->quantity_remaining, 'The newer batch is still untouched');
    }

    private function buy(int $quantity, int $unitPrice, $date): StockBatch
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S'.uniqid()]),
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => $unitPrice]],
            user: $this->user,
            purchaseDate: $date,
        );

        return StockBatch::where('source_id', $purchase->id)
            ->where('source_type', StockBatch::SOURCE_PURCHASE)
            ->firstOrFail();
    }

    private function sell(int $quantity): \App\Models\Sale
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 50_000]],
            user: $this->user, saleDate: now(), amountPaid: 0,
        );
    }
}
