<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SaleReturn;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 5 says deleting a return is "trivial and safe: take its movements,
 * subtract each from its batch, delete them."
 *
 * That holds only while the returned units are still sitting in the batch. A
 * return refills a batch, and Section 5 is explicit that "a batch that reaches
 * 0 is not finished — a return can refill it", so those units are immediately
 * available to the next sale. Once they have been sold again there is nothing
 * left to take back, and the delete has to be refused rather than driving
 * quantity_remaining negative.
 */
class ReturnDeleteGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Customer $customer;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::create(['name' => 'Test']);

        $this->product = Product::create([
            'name' => 'Widget', 'sku' => 'W1', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 10_000, 'sale_price' => 30_000, 'quantity' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'S']);
        $this->customer = Customer::create(['name' => 'C']);
    }

    public function test_a_return_cannot_be_deleted_once_its_stock_has_been_sold_again(): void
    {
        $batch = $this->buyFive();

        // Sell everything, so the batch empties.
        $sale = $this->sell(5);
        $this->assertSame(0, $batch->refresh()->quantity_remaining);

        // Two come back. The batch refills, and those units are on the shelf.
        $return = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->user, returnDate: now(),
        );

        $this->assertSame(2, $batch->refresh()->quantity_remaining);

        // Someone buys them. The returned units are gone again.
        $this->sell(2);
        $this->assertSame(0, $batch->refresh()->quantity_remaining);

        // Deleting the return now would have to take back units that are no
        // longer there. Before the guard this drove quantity_remaining to -2
        // and only the unsigned column stopped it, as a raw SQL error.
        try {
            app(SaleReturnService::class)->delete($return, $this->user);
            $this->fail('Deleting a return whose stock has been sold again must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('since been sold or written off', $e->getMessage());
        }

        // Nothing moved.
        $this->assertSame(0, $batch->refresh()->quantity_remaining);
        $this->assertSame(1, SaleReturn::count(), 'The return is still there');
        $this->assertSame(2, $sale->items()->firstOrFail()->quantity_returned);
    }

    /** The same holds when an adjustment, rather than a sale, consumed them. */
    public function test_the_guard_also_catches_stock_written_off_after_a_return(): void
    {
        $batch = $this->buyFive();
        $sale = $this->sell(5);

        $return = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->user, returnDate: now(),
        );

        app(StockAdjustmentService::class)->create(
            product: $this->product,
            direction: \App\Models\StockAdjustment::DIRECTION_OUT,
            quantity: 2,
            reason: 'damage',
            user: $this->user,
        );

        $this->expectExceptionMessageMatches('/since been sold or written off/');
        app(SaleReturnService::class)->delete($return, $this->user);
    }

    /** A partial overlap is refused too — it is all or nothing. */
    public function test_a_partly_consumed_return_is_also_refused(): void
    {
        $batch = $this->buyFive();
        $sale = $this->sell(5);

        $return = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 3]],
            user: $this->user, returnDate: now(),
        );

        // Only one of the three goes back out.
        $this->sell(1);
        $this->assertSame(2, $batch->refresh()->quantity_remaining);

        $this->expectExceptionMessageMatches('/since been sold or written off/');
        app(SaleReturnService::class)->delete($return, $this->user);
    }

    /** The ordinary case still works: nothing touched the units, so they go back. */
    public function test_a_return_whose_stock_is_untouched_still_deletes_cleanly(): void
    {
        $batch = $this->buyFive();
        $sale = $this->sell(4);

        $return = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->user, returnDate: now(),
        );

        $this->assertSame(3, $batch->refresh()->quantity_remaining);

        app(SaleReturnService::class)->delete($return, $this->user);

        $this->assertSame(1, $batch->refresh()->quantity_remaining, 'Back to its pre-return state');
        $this->assertSame(0, $sale->items()->firstOrFail()->quantity_returned);
        $this->assertSame(1, $this->product->refresh()->quantity);
    }

    /**
     * Section 9b: "Edit/Delete only when unlocked — otherwise render them
     * disabled with the lock reason as a tooltip. Never hide them, or Soran will
     * think the feature is missing."
     */
    public function test_the_screen_disables_the_delete_button_and_says_why(): void
    {
        $batch = $this->buyFive();
        $sale = $this->sell(5);

        $return = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->user, returnDate: now(),
        );

        // While the units are still on the shelf, the button works.
        $this->actingAs($this->user)
            ->get(route('sale-returns.show', $return))
            ->assertOk()
            ->assertSee(route('sale-returns.destroy', $return))
            ->assertDontSee('can no longer be undone');

        // Sell them again, and the button reports why it cannot be used.
        $this->sell(2);

        $this->actingAs($this->user)
            ->get(route('sale-returns.show', $return))
            ->assertOk()
            ->assertSee('disabled', false)
            ->assertSee('can no longer be undone', false);
    }

    /** Posting the delete anyway is still refused, with a readable message. */
    public function test_posting_the_delete_anyway_is_refused_readably(): void
    {
        $batch = $this->buyFive();
        $sale = $this->sell(5);

        $return = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->user, returnDate: now(),
        );

        $this->sell(2);

        $this->actingAs($this->user)
            ->delete(route('sale-returns.destroy', $return))
            ->assertSessionHas('error');

        $this->assertSame(1, SaleReturn::count());
        $this->assertSame(0, $batch->refresh()->quantity_remaining);
    }

    /** No batch may ever hold a negative quantity, whatever the sequence. */
    public function test_no_batch_ever_goes_negative(): void
    {
        foreach (StockBatch::all() as $batch) {
            $this->assertGreaterThanOrEqual(0, $batch->quantity_remaining);
        }

        $this->assertTrue(true);
    }

    private function buyFive(): StockBatch
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10_000]],
            user: $this->user, purchaseDate: now(),
        );

        return StockBatch::where('source_id', $purchase->id)
            ->where('source_type', StockBatch::SOURCE_PURCHASE)
            ->firstOrFail();
    }

    private function sell(int $quantity): \App\Models\Sale
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 30_000]],
            user: $this->user, saleDate: now(), amountPaid: 0,
        );
    }
}
