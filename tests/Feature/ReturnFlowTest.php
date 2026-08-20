<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The return screens end to end, through real HTTP requests — the acceptance
 * test drives the services directly, so this covers the controllers, validation
 * and the forms' contract with them.
 */
class ReturnFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::create(['name' => 'Test']);

        $this->product = Product::create([
            'name' => 'Widget', 'sku' => 'W1', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 10_000, 'sale_price' => 30_000, 'quantity' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'Supplier A']);
        $this->customer = Customer::create(['name' => 'Customer C']);
    }

    public function test_a_sale_return_can_be_made_and_deleted_through_the_screens(): void
    {
        [$sale, $batch] = $this->sellFourOfFive();

        $item = $sale->items()->firstOrFail();

        // The return screen offers what is still returnable.
        $this->actingAs($this->admin)
            ->get(route('sale-returns.create', $sale))
            ->assertOk()
            ->assertSee('Return all');

        $response = $this->actingAs($this->admin)->post(route('sale-returns.store', $sale), [
            'return_date' => today()->toDateString(),
            'reason' => 'Faulty',
            'payment_method' => 'cash',
            'lines' => [['sale_item_id' => $item->id, 'quantity' => 2]],
        ]);

        $return = SaleReturn::latest('id')->firstOrFail();
        $response->assertRedirect(route('sale-returns.show', $return));

        $this->assertSame(60_000, $return->total_amount, '2 units at the line price');
        $this->assertSame(2, $item->refresh()->quantity_returned);
        $this->assertSame(Sale::STATUS_PARTLY_RETURNED, $sale->refresh()->status);
        $this->assertSame(3, $batch->refresh()->quantity_remaining, '1 left + 2 back');

        // Section 7: the refund clears the balance first, the rest is cash out.
        $this->assertSame(0, (int) $this->customer->refresh()->balance);
        $this->assertSame(
            Payment::DIRECTION_OUT,
            $return->payments()->sole()->direction,
            'The cash portion leaves the till'
        );

        // Deleting it puts the stock back exactly and undoes the refund.
        $this->actingAs($this->admin)
            ->delete(route('sale-returns.destroy', $return))
            ->assertRedirect(route('sales.show', $sale));

        $this->assertSame(1, $batch->refresh()->quantity_remaining, 'Back to its pre-return state');
        $this->assertSame(0, $item->refresh()->quantity_returned);
        $this->assertSame(Sale::STATUS_ACTIVE, $sale->refresh()->status, 'Status reverts too');
        $this->assertSame(0, SaleReturn::count(), 'Soft-deleted, so gone from normal queries');
        $this->assertSame(1, SaleReturn::withTrashed()->count(), 'but the row survives');
    }

    public function test_returning_more_than_the_line_allows_is_rejected(): void
    {
        [$sale] = $this->sellFourOfFive();

        $this->actingAs($this->admin)
            ->post(route('sale-returns.store', $sale), [
                'return_date' => today()->toDateString(),
                'payment_method' => 'cash',
                'lines' => [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 99]],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, SaleReturn::withTrashed()->count(), 'Nothing was written');
    }

    /**
     * Section 7: purchase returns ARE limited by the batch — you can't send back
     * goods you no longer hold.
     */
    public function test_a_purchase_return_is_capped_by_what_is_left_in_its_batch(): void
    {
        [$sale, $batch, $purchase] = $this->sellFourOfFive();

        $item = $purchase->items()->firstOrFail();

        // 5 bought, 4 sold, so only 1 can go back to the supplier.
        $this->actingAs($this->admin)
            ->get(route('purchase-returns.create', $purchase))
            ->assertOk()
            ->assertSee('the rest has been sold', false);

        $this->actingAs($this->admin)
            ->post(route('purchase-returns.store', $purchase), [
                'return_date' => today()->toDateString(),
                'payment_method' => 'cash',
                'lines' => [['purchase_item_id' => $item->id, 'quantity' => 3]],
            ])
            ->assertSessionHas('error');

        // One is fine.
        $this->actingAs($this->admin)
            ->post(route('purchase-returns.store', $purchase), [
                'return_date' => today()->toDateString(),
                'payment_method' => 'cash',
                'lines' => [['purchase_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHas('success');

        $this->assertSame(0, $batch->refresh()->quantity_remaining);
        $this->assertSame(Purchase::STATUS_PARTLY_RETURNED, $purchase->refresh()->status);
    }

    /** Section 7: the discount share is opt-in, and defaults to crediting in full. */
    public function test_a_purchase_return_credits_the_full_price_unless_the_share_is_applied(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 200_000]],
            user: $this->admin, purchaseDate: now(), discountAmount: 50_000,
        );

        $item = $purchase->items()->firstOrFail();

        $this->actingAs($this->admin)->post(route('purchase-returns.store', $purchase), [
            'return_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'lines' => [['purchase_item_id' => $item->id, 'quantity' => 1, 'unit_price' => 200_000]],
        ])->assertSessionHas('success');

        $this->assertSame(
            200_000,
            \App\Models\PurchaseReturn::latest('id')->firstOrFail()->total_amount,
            'Credits the full typed price by default'
        );

        // Now with the share applied: 200,000 x 50,000/1,000,000 = 10,000.
        $this->actingAs($this->admin)->post(route('purchase-returns.store', $purchase->refresh()), [
            'return_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'lines' => [[
                'purchase_item_id' => $item->id,
                'quantity' => 1,
                'unit_price' => 200_000,
                'discount_share' => 10_000,
            ]],
        ])->assertSessionHas('success');

        $this->assertSame(
            190_000,
            \App\Models\PurchaseReturn::latest('id')->firstOrFail()->total_amount,
            'Applying the share credits proportionally'
        );
    }

    public function test_the_print_views_render(): void
    {
        [$sale, , $purchase] = $this->sellFourOfFive();

        $this->actingAs($this->admin)->get(route('sales.print', $sale))
            ->assertOk()
            ->assertSee($sale->document_no)
            ->assertSee(setting('shop_name'));

        $this->actingAs($this->admin)->get(route('purchases.print', $purchase))
            ->assertOk()
            ->assertSee($purchase->document_no);

        $return = app(\App\Services\SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );

        $this->actingAs($this->admin)->get(route('sale-returns.print', $return))
            ->assertOk()
            ->assertSee($return->document_no);
    }

    /** @return array{0: Sale, 1: StockBatch, 2: Purchase} */
    private function sellFourOfFive(): array
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(),
        );

        $sale = app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 30_000]],
            user: $this->admin, saleDate: now(), amountPaid: 120_000,
        );

        $batch = StockBatch::where('source_id', $purchase->id)
            ->where('source_type', StockBatch::SOURCE_PURCHASE)
            ->firstOrFail();

        return [$sale, $batch, $purchase];
    }
}
