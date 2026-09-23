<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Swap;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\SwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A faulty item swapped for the same thing — Soran, 2026-09-23.
 *
 * *"if I have same product I change for him and back this faulty PD-17-UK to
 * supplier and refund, not change inv lines"*.
 *
 * ⚠️ The defining property: **the invoice is not touched**. The customer bought
 * one and still owns one, so the printed paper stays true — while the faulty
 * unit goes back to the supplier and a good one leaves the shelf.
 */
class SwapTest extends TestCase
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

    private function buy(Supplier $from, int $quantity, int $cost, int $daysAgo): void
    {
        app(PurchaseService::class)->create(
            supplier: $from,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => $cost]],
            user: $this->user(), purchaseDate: now()->subDays($daysAgo), amountPaid: $quantity * $cost,
        );
    }

    private function sell(int $quantity = 1): Sale
    {
        return Sale::find(app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->pd->id, 'quantity' => $quantity, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: now()->subDays(5),
            amountPaid: $quantity * 60_000, paymentMethod: 'cash',
        )->id);
    }

    /** ⚠️ The whole point: the customer is served and the invoice is untouched. */
    public function test_a_swap_leaves_the_invoice_exactly_as_it_was(): void
    {
        $this->buy($this->bazaar, 2, 40_000, daysAgo: 60);
        $sale = $this->sell(1);
        $line = $sale->items->first();

        $before = $line->only(['quantity', 'unit_price', 'quantity_returned']);

        $swap = app(SwapService::class)->create($line, 1, $this->user());

        $line->refresh();

        $this->assertSame($before, $line->only(['quantity', 'unit_price', 'quantity_returned']),
            'the invoice line was edited');
        $this->assertSame(0, Sale::find($sale->id)->returns()->count(),
            'a sale return was raised, which changes the invoice');

        // The customer keeps their invoice; the shop keeps a record of the swap.
        $this->assertSame('SWP-00001', $swap->document_no);
        $this->assertSame(1, $line->quantity_swapped);
    }

    /**
     * ⚠️ Stock moves ONCE. The faulty unit was already out — it left when it
     * was sold — so what the shelf loses is the replacement, and nothing else.
     */
    public function test_the_shelf_loses_exactly_one_unit(): void
    {
        $this->buy($this->bazaar, 2, 40_000, daysAgo: 60);
        $this->sell(1);

        $this->assertSame(1, $this->pd->fresh()->quantity);

        app(SwapService::class)->create(Sale::first()->items->first(), 1, $this->user());

        $this->assertSame(0, $this->pd->fresh()->quantity, 'the shelf is wrong after a swap');
    }

    /** The faulty one goes back to the supplier it was bought from. */
    public function test_the_faulty_unit_goes_back_to_its_own_supplier(): void
    {
        $this->buy($this->bazaar, 1, 40_000, daysAgo: 60);
        $this->buy($this->sulaimani, 1, 44_000, daysAgo: 30);

        // FIFO sells Bazaar's unit; Sulaimani's is the one left to swap in.
        $sale = $this->sell(1);

        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->assertSame(1, PurchaseReturn::count());

        $raised = PurchaseReturn::with('purchase.supplier')->first();

        $this->assertSame('Bazaar Mobile', $raised->purchase->supplier->name,
            'the wrong supplier was billed for the faulty unit');
        $this->assertSame($raised->id, $swap->purchase_return_id);
        $this->assertSame(40_000, (int) $raised->total_amount);
    }

    /**
     * ⚠️ What the swap cost the shop is shown, not hidden.
     *
     * The faulty one was bought at 40,000 and the replacement came out of a
     * 44,000 layer — so the shop is out 4,000, and the supplier only ever gives
     * back what they were paid.
     */
    public function test_the_swap_records_what_it_cost_the_shop(): void
    {
        $this->buy($this->bazaar, 1, 40_000, daysAgo: 60);
        $this->buy($this->sulaimani, 1, 44_000, daysAgo: 30);

        $sale = $this->sell(1);
        $swap = app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $this->assertSame(40_000, $swap->faulty_cost);
        $this->assertSame(44_000, $swap->replacement_cost);
        $this->assertSame(4_000, $swap->cost());

        // And the money bears it out: 40,000 came back from the supplier and
        // nothing went to the customer.
        $net = (int) Payment::query()
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as net")
            ->value('net');

        // −40,000 −44,000 bought, +60,000 sold, +40,000 back from the supplier.
        $this->assertSame(16_000, $net);
    }

    /**
     * ⚠️ The replacement must not be the very unit just handed over.
     *
     * The faulty one passes back through its own batch on the way to the
     * supplier. If the replacement were taken first — or taken FIFO before the
     * supplier return ran — the oldest layer would hand the customer the same
     * broken unit back.
     */
    public function test_the_replacement_is_not_the_unit_that_just_came_back(): void
    {
        $this->buy($this->bazaar, 1, 40_000, daysAgo: 60);
        $this->buy($this->sulaimani, 1, 44_000, daysAgo: 30);

        $sale = $this->sell(1);
        app(SwapService::class)->create($sale->items->first(), 1, $this->user());

        $out = StockMovement::where('reference_type', StockMovement::REF_SWAP)
            ->where('quantity', '<', 0)->firstOrFail();

        $this->assertSame(44_000, (int) $out->unit_cost,
            'the customer was handed back the faulty unit');
    }

    /** ⚠️ A swapped unit cannot also be returned — it has already been dealt with. */
    public function test_a_swapped_unit_cannot_be_returned_as_well(): void
    {
        $this->buy($this->bazaar, 3, 40_000, daysAgo: 60);
        $sale = $this->sell(1);
        $line = $sale->items->first();

        app(SwapService::class)->create($line, 1, $this->user());

        $this->assertSame(0, $line->refresh()->returnableQuantity(),
            'the same unit could be given back twice');

        $this->expectException(RuntimeException::class);

        app(SaleReturnService::class)->create(
            sale: Sale::find($sale->id),
            lines: [['sale_item_id' => $line->id, 'quantity' => 1]],
            user: $this->user(), returnDate: now(),
        );
    }

    /** Nothing on the shelf, nothing to swap — and it says so before anything moves. */
    public function test_a_swap_is_refused_when_there_is_none_left(): void
    {
        $this->buy($this->bazaar, 1, 40_000, daysAgo: 60);
        $sale = $this->sell(1);

        $this->assertSame(0, $this->pd->fresh()->quantity);

        try {
            app(SwapService::class)->create($sale->items->first(), 1, $this->user());
            $this->fail('swapped a product the shop has none of');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Power bank', $e->getMessage());
        }

        $this->assertSame(0, Swap::count());
        $this->assertSame(0, PurchaseReturn::count(), 'a supplier was billed for a swap that did not happen');
        $this->assertSame(0, Sale::first()->items->first()->quantity_swapped);
    }

    /** A service has nothing to hand over. */
    public function test_a_service_cannot_be_swapped(): void
    {
        $labour = Product::create([
            'name' => 'Fitting', 'kind' => Product::KIND_SERVICE, 'sku' => 'LAB-1',
            'barcode' => 'LAB-1-B', 'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 0, 'sale_price' => 10_000, 'quantity' => 0,
        ]);

        $sale = Sale::find(app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $labour->id, 'quantity' => 1, 'unit_price' => 10_000]],
            user: $this->user(), saleDate: now(), amountPaid: 10_000,
        )->id);

        $this->expectException(RuntimeException::class);

        app(SwapService::class)->create($sale->items->first(), 1, $this->user());
    }
}
