<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 11: "Unit-test these" — the cases the doc names beyond the 10b
 * scenario. The concurrency test lives in ConcurrencyTest.
 */
class FifoEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Supplier $supplier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::create(['name' => 'Test']);

        $this->product = Product::create([
            'name' => 'Product A', 'sku' => 'A', 'category_id' => $category->id,
            'unit' => 'pcs', 'purchase_price' => 0, 'sale_price' => 260_000, 'quantity' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'Bazaar Mobile']);
        $this->customer = Customer::create(['name' => 'Rebin Karim']);
    }

    /**
     * Section 10's worked example: a sale line spanning two batches, then a
     * partial return that must unwind across both.
     */
    public function test_fifo_spans_multiple_batches_and_returns_unwind_in_reverse(): void
    {
        $p1 = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 200_000]],
            user: $this->user, purchaseDate: now(),
        );

        $p2 = app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Zagros Trading']),
            lines: [['product_id' => $this->product->id, 'quantity' => 6, 'unit_price' => 210_000]],
            user: $this->user, purchaseDate: now(),
        );

        $a1 = StockBatch::where('source_id', $p1->id)->firstOrFail();
        $a2 = StockBatch::where('source_id', $p2->id)->firstOrFail();

        // Line 1: 3 @ 260,000 (default). Line 2: 2 @ 250,000 (manual).
        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [
                ['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 260_000],
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 250_000],
            ],
            user: $this->user, saleDate: now(),
        );

        $this->assertSame(1_280_000, $sale->total_amount);

        // FIFO: line 1 -> 3 x A1; line 2 -> 1 x A1 + 1 x A2 -> COGS 1,010,000
        $cogs = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->get()->sum(fn ($m) => abs($m->quantity) * $m->unit_cost);

        $this->assertSame(1_010_000, (int) $cogs, 'COGS spans both batches');
        $this->assertSame(0, $a1->refresh()->quantity_remaining, 'A1 -> 0/4');
        $this->assertSame(5, $a2->refresh()->quantity_remaining, 'A2 -> 5/6');

        // The second line drew from two batches, so returning both its units must
        // give one back to A2 first, then one to A1.
        $line2 = $sale->items()->orderBy('sequence')->get()[1];

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $line2->id, 'quantity' => 2]],
            user: $this->user, returnDate: now(),
        );

        $this->assertSame(1, $a1->refresh()->quantity_remaining, 'A1 -> 1/4');
        $this->assertSame(6, $a2->refresh()->quantity_remaining, 'A2 -> 6/6, never above quantity_in');

        // COGS reversal 410,000 = 210,000 + 200,000
        $reversed = StockMovement::where('reference_type', StockMovement::REF_SALE_RETURN)
            ->get()->sum(fn ($m) => $m->quantity * $m->unit_cost);

        $this->assertSame(410_000, (int) $reversed);

        // Rebin owes 1,280,000 - 500,000 = 780,000
        $this->assertSame(780_000, (int) $this->customer->refresh()->balance);
    }

    /** Section 8: a purchase whose batch has been drawn on is locked. */
    public function test_a_purchase_locks_once_its_stock_has_been_sold(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 200_000]],
            user: $this->user, purchaseDate: now(),
        );

        $this->assertTrue($purchase->canBeModified()['allowed'], 'Untouched purchase is editable');

        app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 260_000]],
            user: $this->user, saleDate: now(),
        );

        $result = $purchase->refresh()->canBeModified();

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('3 units', $result['reason']);

        // Section 8: "Locks are computed, never stored." There is no is_locked
        // column to go stale.
        $this->assertArrayNotHasKey('is_locked', $purchase->getAttributes());
    }

    /**
     * Section 8: a full customer return restores a batch to remaining == in, and
     * the Edit button reappears by itself with no extra code.
     */
    public function test_a_full_return_unlocks_the_purchase_again(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 200_000]],
            user: $this->user, purchaseDate: now(),
        );

        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 260_000]],
            user: $this->user, saleDate: now(),
        );

        $this->assertFalse($purchase->refresh()->canBeModified()['allowed']);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 3]],
            user: $this->user, returnDate: now(),
        );

        $this->assertTrue(
            $purchase->refresh()->canBeModified()['allowed'],
            'The lock lifts by itself once the batch is whole again'
        );

        $this->assertSame(Sale::STATUS_RETURNED, $sale->refresh()->status);
    }

    /** Section 7: quantity_returned is cumulative and caps further returns. */
    public function test_cumulative_quantity_returned_caps_the_line(): void
    {
        app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 200_000]],
            user: $this->user, purchaseDate: now(),
        );

        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 260_000]],
            user: $this->user, saleDate: now(),
        );

        $item = $sale->items()->firstOrFail();
        $service = app(SaleReturnService::class);

        $service->create($sale, [['sale_item_id' => $item->id, 'quantity' => 2]], $this->user, now());
        $this->assertSame(2, $item->refresh()->quantity_returned);
        $this->assertSame(1, $item->returnableQuantity());

        $this->expectExceptionMessage('Only 1 left to return on that line.');
        $service->create($sale, [['sale_item_id' => $item->id, 'quantity' => 2]], $this->user, now());
    }

    /**
     * Section 7: the discount share defaults to 0 — the supplier credits the full
     * typed unit price unless Soran chooses otherwise.
     */
    public function test_purchase_return_discount_share_is_opt_in(): void
    {
        // Subtotal 1,000,000, discount 50,000, grand total 950,000.
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [
                ['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 200_000],
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 20_000],
            ],
            user: $this->user, purchaseDate: now(), discountAmount: 50_000,
        );

        $this->assertSame(950_000, $purchase->grand_total);
        $this->assertSame(950_000, (int) $this->supplier->refresh()->balance);

        $line = $purchase->items()->orderBy('sequence')->firstOrFail();

        // Default: credit the full 200,000, not the discounted 190,000.
        $return = app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $line->id, 'quantity' => 1]],
            user: $this->user, returnDate: now(),
        );

        $this->assertSame(200_000, $return->total_amount, 'Credits the full typed price by default');
        $this->assertSame(0, $return->items()->firstOrFail()->discount_share);
        $this->assertSame(750_000, (int) $this->supplier->refresh()->balance);

        // Applying the share explicitly credits 190,000 instead.
        $return2 = app(PurchaseReturnService::class)->create(
            purchase: $purchase->refresh(),
            lines: [['purchase_item_id' => $line->id, 'quantity' => 1, 'discount_share' => 10_000]],
            user: $this->user, returnDate: now(),
        );

        $this->assertSame(190_000, $return2->total_amount, 'Applying the share credits proportionally');
        $this->assertSame(560_000, (int) $this->supplier->refresh()->balance);

        $this->assertSame(Purchase::STATUS_PARTLY_RETURNED, $purchase->refresh()->status);
    }

    /**
     * Section 7: a sale return is never validated against stock on hand. A
     * returning customer is ADDING stock.
     */
    public function test_a_sale_return_is_not_blocked_by_empty_stock(): void
    {
        app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 200_000]],
            user: $this->user, purchaseDate: now(),
        );

        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 260_000]],
            user: $this->user, saleDate: now(),
        );

        $this->assertSame(0, $this->product->refresh()->quantity, 'Nothing left on the shelf');

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->user, returnDate: now(),
        );

        $this->assertSame(2, $this->product->refresh()->quantity, 'The return puts stock back');
    }

    /** Section 4: the Cash Customer must always be paid in full — no loan. */
    public function test_the_cash_customer_cannot_buy_on_loan(): void
    {
        app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 200_000]],
            user: $this->user, purchaseDate: now(),
        );

        $this->expectExceptionMessage('The Cash Customer must be paid in full.');

        app(SaleService::class)->create(
            customer: Customer::cashCustomer(),
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 260_000]],
            user: $this->user, saleDate: now(), amountPaid: 0,
        );
    }
}
