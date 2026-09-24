<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A faulty item goes back to the supplier — Soran, 2026-09-23.
 *
 * *"when return an item and this item dont add to shelf or stok, just i hold
 * it, and supplier refund as cash to me"*.
 *
 * ⚠️ The money always worked; what was missing was being handed the right
 * document. A purchase return against the purchase the unit came from does
 * BOTH jobs — takes it out of stock and brings the supplier's cash in — and
 * the shop ends square. Adjusting it out as `damage` instead books the cost as
 * the shop's own loss and then makes the purchase return impossible, because
 * the batch is empty.
 */
class FaultyReturnTest extends TestCase
{
    use RefreshDatabase;

    private Product $pd;

    private Supplier $bazaar;

    private Supplier $sulaimani;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->pd = Product::create([
            'name' => 'Power bank 17000mAh UK', 'kind' => Product::KIND_STOCK,
            'sku' => 'PD-17-UK', 'barcode' => 'PD-17-UK-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 40_000, 'sale_price' => 60_000, 'quantity' => 0,
        ]);

        $this->bazaar = Supplier::create(['name' => 'Bazaar Mobile', 'phone' => '0770', 'is_active' => true]);
        $this->sulaimani = Supplier::create(['name' => 'Sulaimani Traders', 'phone' => '0771', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
    }

    private function user(): User
    {
        return User::first();
    }

    private function buy(Supplier $from, int $quantity, int $cost, int $daysAgo): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $from,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => $cost]],
            user: $this->user(), purchaseDate: now()->subDays($daysAgo), amountPaid: $quantity * $cost,
        );
    }

    private function sell(int $quantity): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: now()->subDays(5),
            amountPaid: $quantity * 60_000, paymentMethod: 'cash',
        );
    }

    /** ⚠️ The whole point: the shop finishes square and the unit is not on the shelf. */
    public function test_a_faulty_return_sends_it_back_and_leaves_the_shop_square(): void
    {
        $this->buy($this->bazaar, 1, 40_000, daysAgo: 40);
        $sale = Sale::find($this->sell(1)->id);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            user: $this->user(), returnDate: now(), reason: 'Faulty',
            faultyLines: [$sale->items->first()->id],
        );

        $this->assertSame(0, $this->pd->fresh()->quantity, 'the faulty unit is still sellable');
        $this->assertSame(1, PurchaseReturn::count(), 'no purchase return was raised');

        $net = (int) Payment::query()
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as net")
            ->value('net');

        $this->assertSame(0, $net, 'somebody ate the cost of the faulty unit');

        // ⚠️ And nothing was written off: the supplier carried it, not the shop.
        $this->assertSame(0, StockMovement::where('reference_type', StockMovement::REF_ADJUSTMENT)->count());
    }

    /** Leave it unticked and only the customer's half happens. */
    public function test_an_unticked_return_raises_no_purchase_return(): void
    {
        $this->buy($this->bazaar, 1, 40_000, daysAgo: 40);
        $sale = Sale::find($this->sell(1)->id);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            user: $this->user(), returnDate: now(),
        );

        $this->assertSame(0, PurchaseReturn::count());
        $this->assertSame(1, $this->pd->fresh()->quantity, 'the unit should be back on the shelf');
    }

    /**
     * ⚠️ One sale line, two purchases, two suppliers — two purchase returns.
     *
     * Three sold: FIFO took two from Bazaar at 40,000 and one from Sulaimani at
     * 44,000. All three come back faulty, so each supplier gets its own units
     * back at its own cost.
     */
    public function test_a_line_filled_from_two_purchases_goes_back_to_both(): void
    {
        $this->buy($this->bazaar, 2, 40_000, daysAgo: 60);
        $this->buy($this->sulaimani, 2, 44_000, daysAgo: 30);

        $sale = Sale::find($this->sell(3)->id);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 3]],
            user: $this->user(), returnDate: now(), faultyLines: [$sale->items->first()->id],
        );

        $this->assertSame(2, PurchaseReturn::count(), 'both suppliers should have been billed');

        $bySupplier = PurchaseReturn::with('purchase.supplier')->get()
            ->keyBy(fn ($r) => $r->purchase->supplier->name);

        $this->assertSame(80_000, (int) $bySupplier['Bazaar Mobile']->total_amount);
        $this->assertSame(44_000, (int) $bySupplier['Sulaimani Traders']->total_amount);

        // One left on the shelf: four bought, three sold and sent back.
        $this->assertSame(1, $this->pd->fresh()->quantity);
    }

    /**
     * ⚠️ **LAST CONSUMED, FIRST RETURNED — and the supplier must match.**
     *
     * Three sold across two purchases; only one comes back. The restore puts
     * that unit into the batch it last took from, which is Sulaimani's — so
     * Sulaimani is who gets billed. Walking the units oldest-first would refund
     * Bazaar at 40,000 for a unit that went back into Sulaimani's batch, and
     * the books would disagree with the shelf.
     */
    public function test_the_unit_sent_back_is_the_one_the_return_actually_restored(): void
    {
        $this->buy($this->bazaar, 2, 40_000, daysAgo: 60);
        $this->buy($this->sulaimani, 2, 44_000, daysAgo: 30);

        $sale = Sale::find($this->sell(3)->id);

        app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            user: $this->user(), returnDate: now(), faultyLines: [$sale->items->first()->id],
        );

        $this->assertSame(1, PurchaseReturn::count());

        $raised = PurchaseReturn::with('purchase.supplier')->first();

        $this->assertSame('Sulaimani Traders', $raised->purchase->supplier->name,
            'the wrong supplier was billed for the returned unit');
        $this->assertSame(44_000, (int) $raised->total_amount, 'billed at the wrong cost');
    }

    /** The trace itself, in the order the restore uses. */
    public function test_origins_are_listed_last_consumed_first(): void
    {
        $this->buy($this->bazaar, 2, 40_000, daysAgo: 60);
        $this->buy($this->sulaimani, 2, 44_000, daysAgo: 30);

        $item = SaleItem::where('sale_id', $this->sell(3)->id)->firstOrFail();

        $origins = app(SaleReturnService::class)->originsFor($item, 3);

        $this->assertSame([44_000, 40_000], $origins->pluck('unit_cost')->all());
        $this->assertSame([1, 2], $origins->pluck('quantity')->all());
        $this->assertSame('Sulaimani Traders', $origins->first()->supplier->name);
    }

    /**
     * ⚠️ Stock with no purchase behind it has no supplier to go back to.
     *
     * Opening stock entered by adjustment carries no `purchase_item_id`. The
     * customer's return must still work; there is simply nobody to bill, and a
     * `damage` adjustment is then the shop's own decision.
     */
    public function test_stock_that_never_came_from_a_purchase_is_reported_not_billed(): void
    {
        /*
         * ⚠️ A real purchase of something else has to exist, or this test
         * cannot fail: with no purchases in the shop at all, code that wrongly
         * reached for "any purchase" would find none and fall through to the
         * right answer by accident. It was written that way first and a
         * sabotage walked straight through it.
         */
        $other = Product::create([
            'name' => 'USB cable', 'kind' => Product::KIND_STOCK, 'sku' => 'CBL-1',
            'barcode' => 'CBL-1-B', 'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 1_000, 'sale_price' => 2_000, 'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: $this->bazaar,
            lines: [['product_id' => $other->id, 'quantity' => 5, 'unit_price' => 1_000]],
            user: $this->user(), purchaseDate: now()->subDays(10), amountPaid: 5_000,
        );

        app(StockAdjustmentService::class)->create(
            product: Product::findOrFail($this->pd->id), direction: 'in', quantity: 1,
            reason: 'correction', user: $this->user(), unitCost: 40_000, notes: 'opening stock');

        $sale = Sale::find($this->sell(1)->id);
        $item = $sale->items->first();

        $origins = app(SaleReturnService::class)->originsFor($item, 1);

        $this->assertCount(1, $origins);
        $this->assertNull($origins->first()->purchase, 'an adjustment batch reported a purchase');

        app(SaleReturnService::class)->create(
            sale: $sale, lines: [['sale_item_id' => $item->id, 'quantity' => 1]],
            user: $this->user(), returnDate: now(), faultyLines: [$item->id],
        );

        $this->assertSame(0, PurchaseReturn::count(), 'a supplier was billed for stock it never sold');
        $this->assertSame(1, $this->pd->fresh()->quantity, 'the customer return did not happen');
    }

    /**
     * ⚠️ Billing a supplier is `purchase_returns.create`, not
     * `sale_returns.create`. A form can be edited, so the rule is enforced
     * where it counts and not only where the checkbox is drawn.
     */
    public function test_somebody_who_may_take_a_return_may_not_thereby_bill_a_supplier(): void
    {
        $this->buy($this->bazaar, 1, 40_000, daysAgo: 40);
        $sale = Sale::find($this->sell(1)->id);

        $counter = User::create([
            'name' => 'Hawkar', 'email' => 'hawkar@example.com', 'password' => 'x',
            'role' => User::ROLE_USER, 'is_active' => true,
        ]);
        $counter->permissions()->attach(Permission::whereIn('key', [
            'auth.login', 'sales.view', 'sale_returns.view', 'sale_returns.create',
        ])->pluck('id'));

        $page = $this->actingAs($counter)->get(route('sale-returns.create', $sale));
        $page->assertOk();
        $page->assertDontSee(__('Faulty — send back to the supplier'), false);

        $this->actingAs($counter)->post(route('sale-returns.store', $sale), [
            'return_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'lines' => [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            'faulty' => [$sale->items->first()->id],
        ])->assertRedirect();

        $this->assertSame(0, PurchaseReturn::count(), 'the counter billed a supplier');
        $this->assertSame(1, $this->pd->fresh()->quantity);
    }

    /** The screen names the supplier before the shop commits to anything. */
    public function test_the_return_screen_names_the_purchase_and_the_supplier(): void
    {
        $purchase = $this->buy($this->bazaar, 1, 40_000, daysAgo: 40);
        $sale = Sale::find($this->sell(1)->id);

        $this->actingAs($this->user())
            ->get(route('sale-returns.create', $sale))
            ->assertOk()
            ->assertSee(__('Faulty — send back to the supplier'), false)
            ->assertSee($purchase->document_no)
            ->assertSee('Bazaar Mobile');
    }

    /**
     * ⚠️ Soran, 2026-09-23: *"i dont know inv number, just serch invoices are
     * have same product line"*. The sales list matched document numbers only.
     */
    public function test_sales_can_be_found_by_the_product_on_them(): void
    {
        $this->buy($this->bazaar, 1, 40_000, daysAgo: 40);
        $sale = $this->sell(1);

        foreach (['PD-17-UK', 'Power bank', 'Karwan', $sale->document_no] as $term) {
            $this->actingAs($this->user())
                ->get(route('sales.index', ['search' => $term]))
                ->assertOk()
                ->assertSee($sale->document_no);
        }

        $this->actingAs($this->user())
            ->get(route('sales.index', ['search' => 'something else entirely']))
            ->assertOk()
            ->assertDontSee($sale->document_no);
    }
}
