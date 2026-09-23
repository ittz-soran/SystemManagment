<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
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
 * The warranty on what the shop SELLS — Soran, 2026-09-23.
 *
 * *"i sell this 1 month ago PD-17-UK now not working customer back it to change
 * on warenty, but now i dont have stok same this"*.
 *
 * ⚠️ `products.warranty_days` existed for repairs and was wired to nothing
 * else: not on the product form, not on the invoice, and never looked at when
 * a sold item came back.
 *
 * ⚠️ And the money already worked, through a document the system has had all
 * along — *"supllier get me cost of it"*. A faulty unit is not a damage
 * write-off; it goes back to the supplier on a purchase return, and the shop
 * ends square. The last test here is that chain, because getting it wrong
 * makes the shop pay for the supplier's fault.
 */
class SaleWarrantyTest extends TestCase
{
    use RefreshDatabase;

    private Product $powerBank;

    private Supplier $supplier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->powerBank = Product::create([
            'name' => 'Power bank 17000mAh UK', 'kind' => Product::KIND_STOCK,
            'sku' => 'PD-17-UK', 'barcode' => 'PD-17-UK-B',
            'category_id' => Category::first()->id, 'unit' => 'pcs',
            'purchase_price' => 40_000, 'sale_price' => 60_000, 'quantity' => 0,
            'warranty_days' => 30,
        ]);

        $this->supplier = Supplier::create(['name' => 'Bazaar Mobile', 'phone' => '0770', 'is_active' => true]);
        $this->customer = Customer::create(['name' => 'Karwan', 'phone' => '0750']);
    }

    private function user(): User
    {
        return User::first();
    }

    private function buyOne(int $daysAgo = 40): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->powerBank->id, 'quantity' => 1, 'unit_price' => 40_000]],
            user: $this->user(), purchaseDate: now()->subDays($daysAgo), amountPaid: 40_000,
        );
    }

    private function sellOne(int $daysAgo): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->powerBank->id, 'quantity' => 1, 'unit_price' => 60_000]],
            user: $this->user(), saleDate: now()->subDays($daysAgo),
            amountPaid: 60_000, paymentMethod: 'cash',
        );
    }

    /** The promise is written onto the line when the sale is made. */
    public function test_the_warranty_is_copied_onto_the_sale_line(): void
    {
        $this->buyOne();
        $sale = $this->sellOne(daysAgo: 10);

        $line = $sale->items->first();

        $this->assertSame(30, $line->warranty_days);
        $this->assertSame(
            $sale->sale_date->copy()->addDays(30)->toDateString(),
            $line->warrantyEndsOn()->toDateString(),
        );
        $this->assertTrue($line->underWarranty(), 'ten days into a thirty-day warranty');
    }

    /**
     * ⚠️ **The promise does not move when the product is edited.**
     *
     * The shop cutting the warranty to seven days next month must not shorten
     * what the invoice in the customer's hand already says. Same rule a repair
     * line follows, and the reason the number is copied rather than read.
     */
    public function test_editing_the_product_later_does_not_rewrite_what_was_promised(): void
    {
        $this->buyOne();
        $sale = $this->sellOne(daysAgo: 10);

        $this->powerBank->forceFill(['warranty_days' => 7])->save();

        $line = $sale->fresh('items')->items->first();

        $this->assertSame(30, $line->warranty_days, 'the promise followed the product');
        $this->assertTrue($line->underWarranty(), 'a shortened product warranty expired an old sale');
    }

    /** Past the thirty days it says so, rather than saying nothing. */
    public function test_a_sale_outside_its_warranty_says_so(): void
    {
        $this->buyOne(daysAgo: 100);
        $sale = $this->sellOne(daysAgo: 45);

        $line = $sale->items->first();

        $this->assertFalse($line->underWarranty());
        $this->assertSame(
            $sale->sale_date->copy()->addDays(30)->toDateString(),
            $line->warrantyEndsOn()->toDateString(),
        );
    }

    /** No warranty offered is null, and null is not "expired today". */
    public function test_a_product_with_no_warranty_carries_none(): void
    {
        $this->powerBank->forceFill(['warranty_days' => null])->save();

        $this->buyOne();
        $line = $this->sellOne(daysAgo: 1)->items->first();

        $this->assertNull($line->warranty_days);
        $this->assertNull($line->warrantyEndsOn());
        $this->assertFalse($line->underWarranty());
    }

    /** The invoice carries it, because the customer walks away with that paper. */
    public function test_the_invoice_prints_the_warranty(): void
    {
        $this->buyOne();
        $sale = $this->sellOne(daysAgo: 2);

        $this->actingAs($this->user())
            ->get(route('sales.print', $sale))
            ->assertOk()
            ->assertSee(__('Warranty'))
            ->assertSee($sale->sale_date->copy()->addDays(30)->format(setting('date_format', 'Y-m-d')));
    }

    /** And the return screen says it at the moment the shop decides. */
    public function test_the_return_screen_flags_a_line_still_under_warranty(): void
    {
        $this->buyOne();
        $sale = $this->sellOne(daysAgo: 10);

        $page = $this->actingAs($this->user())->get(route('sale-returns.create', $sale));

        $page->assertOk();
        $page->assertSee(__('Still under warranty'));
        $page->assertSee(__('Under warranty until :date', [
            'date' => $sale->sale_date->copy()->addDays(30)->format(setting('date_format', 'Y-m-d')),
        ]), false);
    }

    /**
     * ⚠️ **THE WHOLE CASE, END TO END: the shop must finish square.**
     *
     * Soran: *"supllier get me cost of it"*. The faulty unit goes back to the
     * supplier on a purchase return against the purchase it came from — not
     * written off as damage, which would put the 40,000 in the shop's own P&L
     * and make it pay for the supplier's fault.
     */
    public function test_a_warranty_case_leaves_the_shop_square(): void
    {
        $purchase = $this->buyOne();
        $sale = $this->sellOne(daysAgo: 30);

        $this->assertSame(0, $this->powerBank->fresh()->quantity);

        // The customer brings it back.
        $sale = Sale::find($sale->id);
        app(SaleReturnService::class)->create(
            sale: $sale, lines: [['sale_item_id' => $sale->items->first()->id, 'quantity' => 1]],
            user: $this->user(), returnDate: now(), reason: 'Faulty, inside its thirty days',
        );

        // ⚠️ And here it is sellable again, which is the trap the screen warns about.
        $this->assertSame(1, $this->powerBank->fresh()->quantity,
            'the returned unit is not back in stock, so the warning is describing nothing');

        // Sent back where it came from.
        $purchase = Purchase::find($purchase->id);
        app(PurchaseReturnService::class)->create(
            purchase: $purchase, lines: [['purchase_item_id' => $purchase->items->first()->id, 'quantity' => 1]],
            user: $this->user(), returnDate: now(), reason: 'Faulty under warranty',
        );

        $this->assertSame(0, $this->powerBank->fresh()->quantity, 'the faulty unit is still on the shelf');

        // −40,000 out, +60,000 in, −60,000 back, +40,000 back: nothing lost.
        $cash = Payment::query()
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as net")
            ->value('net');

        $this->assertSame(0, (int) $cash, 'somebody ate the cost of the faulty unit');

        // And nothing was written off — the supplier carried it, not the shop.
        $this->assertSame(0, StockMovement::where('reference_type', StockMovement::REF_ADJUSTMENT)->count());
    }
}
