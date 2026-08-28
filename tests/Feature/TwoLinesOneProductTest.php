<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The same product, twice, on one document, at two prices.
 *
 * Section 4 designs for it — "one sale can list the same product on two lines
 * at two prices, and both may draw from the same batch" — and it is the whole
 * reason stock_movements carries reference_item_id. The engine did it all
 * along; the cart had no way to ask for it, because scanning the same barcode
 * again folds into the line that is already there.
 *
 * So the row carries a button that splits it, and these are the two halves of
 * that: the engine keeps the lines apart, and the screen offers the button.
 */
class TwoLinesOneProductTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin);

        $this->product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32', 'barcode' => '6221031492754',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000,
            'quantity' => 0, 'is_active' => true,
        ]);
    }

    /** Two lines, two prices, and the movements can tell them apart. */
    public function test_a_sale_can_carry_the_same_product_twice(): void
    {
        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );

        $sale = app(SaleService::class)->create(
            customer: Customer::create(['name' => 'Karwan']),
            lines: [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 15_000],
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 12_000],
            ],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );

        $items = $sale->items()->orderBy('id')->get();

        $this->assertCount(2, $items, 'The two lines stay two lines');
        $this->assertSame([15_000, 12_000], $items->pluck('unit_price')->map(fn ($p) => (int) $p)->all());
        $this->assertSame(42_000, (int) $sale->total_amount);

        // Section 4: reference_item_id points at the line, not just the sale,
        // so a return against one of them can find what that one consumed.
        $movements = StockMovement::where('reference_type', StockMovement::REF_SALE)
            ->where('reference_id', $sale->id)
            ->get();

        $this->assertSame(
            $items->pluck('id')->sort()->values()->all(),
            $movements->pluck('reference_item_id')->unique()->sort()->values()->all(),
            'Each line has its own movements',
        );
    }

    public function test_a_purchase_can_bring_the_same_product_in_at_two_prices(): void
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [
                ['product_id' => $this->product->id, 'quantity' => 6, 'unit_price' => 10_000],
                ['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 11_500],
            ],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );

        $this->assertCount(2, $purchase->items);
        $this->assertSame(10, (int) $this->product->refresh()->quantity);

        // Rule 1: every stock-in makes a batch, and rule 2 says the batch cost
        // is the price typed — so two prices are two layers, not an average.
        $costs = $this->product->stockBatches()->orderBy('id')->pluck('unit_cost')
            ->map(fn ($c) => (int) $c)->all();

        $this->assertSame([10_000, 11_500], $costs);
    }

    /** The button that asks for the second line is on both carts. */
    public function test_both_carts_offer_a_second_line(): void
    {
        foreach ([route('sales.create'), route('purchases.create')] as $cart) {
            $this->actingAs($this->admin)->get($cart)
                ->assertOk()
                ->assertSee('data-role="split"', false)
                ->assertSee(__('Another line for this product, at its own price'));
        }
    }
}
