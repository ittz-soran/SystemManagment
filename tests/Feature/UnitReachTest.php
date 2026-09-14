<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unit reaches every screen that shows or takes a quantity.
 *
 * **Soran, 2026-09-14:** *"just have deferent units Pisces, Karton, Kgm,… are
 * user can add or remove … Just buy and sale on same unit"*.
 *
 * ⚠️ Read that last clause as the design. There is **no conversion** — no pack
 * ratio, no "1 karton = 12 pcs", no second unit on a line. A product is
 * measured one way and bought and sold that way, and the whole of the feature
 * is the managed list in Settings plus this: wherever a quantity appears, the
 * unit appears beside it. A bare "12" on a shop that sells cable by the metre
 * and pens by the carton is a number nobody can act on.
 *
 * So these tests are deliberately dull. Each one opens a screen and looks for
 * the unit. They exist because the unit is easy to drop when a view is edited
 * for some other reason, and nothing else would notice.
 *
 * The unit here is `karton`, not `pcs`: `pcs` is the seeded default and would
 * pass on a screen that had never heard of units at all.
 */
class UnitReachTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::where('email', 'admin@example.com')->firstOrFail();

        $this->product = Product::create([
            'name' => 'Pilot Pen Blue', 'sku' => 'PN10', 'unit' => 'karton',
            'category_id' => Category::firstOrFail()->id,
            'purchase_price' => 9_000, 'sale_price' => 12_000,
        ]);
    }

    private function aPurchase(): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Erbil Wholesale']),
            lines: [['product_id' => $this->product->id, 'quantity' => 20, 'unit_price' => 9_000]],
            user: $this->user,
            purchaseDate: today()->subDay(),
        );
    }

    private function aSale(): Sale
    {
        $this->aPurchase();

        return app(SaleService::class)->create(
            customer: Customer::create(['name' => 'Hawkar Osman']),
            lines: [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 12_000]],
            user: $this->user,
            saleDate: today(),
            amountPaid: 36_000,
        );
    }

    // ---- The helper the views go through --------------------------------

    /** `qty()` writes the number the way the shop reads numbers, then the unit. */
    public function test_the_helper_writes_the_unit_after_the_number(): void
    {
        $this->assertSame('1,250 karton', qty(1250, 'karton'));
        $this->assertSame('0 kg', qty(null, 'kg'));
    }

    /**
     * ⚠️ And it says nothing when there is nothing to say.
     *
     * A product whose unit is blank — imported from a spreadsheet that had no
     * such column — must read "12", never "12 " with a space hanging off it.
     */
    public function test_the_helper_leaves_a_blank_unit_alone(): void
    {
        $this->assertSame('12', qty(12, ''));
        $this->assertSame('12', qty(12, null));
        $this->assertSame('12', qty(12, 'karton', withUnit: false));
    }

    // ---- Screens that SHOW a quantity -----------------------------------

    public function test_the_product_page_says_what_it_is_counted_in(): void
    {
        $this->aPurchase();

        $this->actingAs($this->user)->get(route('products.show', $this->product))
            ->assertOk()
            ->assertSee('20 karton');
    }

    public function test_the_product_list_says_it(): void
    {
        $this->aPurchase();

        $this->actingAs($this->user)->get(route('products.index'))
            ->assertOk()
            ->assertSee('20 karton');
    }

    public function test_a_sale_says_it(): void
    {
        $sale = $this->aSale();

        $this->actingAs($this->user)->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('3 karton');
    }

    public function test_a_purchase_says_it(): void
    {
        $purchase = $this->aPurchase();

        $this->actingAs($this->user)->get(route('purchases.show', $purchase))
            ->assertOk()
            ->assertSee('20 karton');
    }

    /** The printed document too — it is what the customer keeps. */
    public function test_the_printed_sale_says_it(): void
    {
        $sale = $this->aSale();

        $this->actingAs($this->user)->get(route('sales.print', $sale))
            ->assertOk()
            ->assertSee('3 karton');
    }

    public function test_the_adjustment_list_says_it(): void
    {
        $this->aPurchase();
        $this->anAdjustment();

        $this->actingAs($this->user)->get(route('stock-adjustments.index'))
            ->assertOk()
            ->assertSee('2 karton');
    }

    private function anAdjustment(): StockAdjustment
    {
        return app(StockAdjustmentService::class)->create(
            product: $this->product,
            direction: 'out',
            quantity: 2,
            reason: 'damage',
            user: $this->user,
            adjustedAt: today(),
        );
    }

    // ---- Screens where a quantity is TYPED ------------------------------

    /**
     * The two carts are built in JavaScript from the search response, so the
     * unit has to travel in that JSON or the box beside it stays empty.
     */
    public function test_the_product_search_carries_the_unit(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('products.search', ['q' => 'PN10']))
            ->assertOk()
            ->assertJsonPath('products.0.unit', 'karton');
    }

    /** And an edit reopens the cart from the saved lines, not from a search. */
    public function test_reopening_a_sale_carries_the_unit_into_the_cart(): void
    {
        $sale = $this->aSale();

        $this->actingAs($this->user)->get(route('sales.edit', $sale))
            ->assertOk()
            ->assertSee('"unit":"karton"', escape: false);
    }

    public function test_reopening_a_purchase_carries_the_unit_into_the_cart(): void
    {
        $purchase = $this->aPurchase();

        $this->actingAs($this->user)->get(route('purchases.edit', $purchase))
            ->assertOk()
            ->assertSee('"unit":"karton"', escape: false);
    }

    /** The sale return screen takes a count back, in the same unit. */
    public function test_the_sale_return_screen_says_it(): void
    {
        $sale = $this->aSale();

        $this->actingAs($this->user)->get(route('sale-returns.create', ['sale' => $sale->id]))
            ->assertOk()
            ->assertSee('3 karton')
            ->assertSee('<span class="input-group-text">karton</span>', escape: false);
    }

    public function test_the_purchase_return_screen_says_it(): void
    {
        $purchase = $this->aPurchase();

        $this->actingAs($this->user)->get(route('purchase-returns.create', ['purchase' => $purchase->id]))
            ->assertOk()
            ->assertSee('20 karton')
            ->assertSee('<span class="input-group-text">karton</span>', escape: false);
    }

    /** The adjustment modal opens on a row, and carries that row's unit. */
    public function test_the_adjustment_edit_button_carries_the_unit(): void
    {
        $this->aPurchase();
        $this->anAdjustment();

        $this->actingAs($this->user)->get(route('stock-adjustments.index'))
            ->assertOk()
            ->assertSee('data-unit="karton"', escape: false);
    }

    /** A new product's opening count is in the unit chosen on the same form. */
    public function test_the_new_product_form_echoes_the_chosen_unit(): void
    {
        $this->actingAs($this->user)->get(route('products.create'))
            ->assertOk()
            ->assertSee('id="opening_unit"', escape: false)
            ->assertSee('id="unit"', escape: false);
    }

    // ---- The screen that had no unit at all ----------------------------

    /**
     * A second-hand machine is bought and sold like anything else, and until
     * now it was the one entry screen with no unit on it — it took whatever
     * `SecondHandService` defaulted to and never asked.
     *
     * (The list itself — writing it, tidying it, and what happens to a product
     * measured in a unit since retired — is UnitsTest's job, not this one's.)
     */
    public function test_the_second_hand_screen_offers_the_list(): void
    {
        Setting::put('units', "karton\nkgm");

        $this->actingAs($this->user)->get(route('second-hand.create'))
            ->assertOk()
            ->assertSee('name="unit"', escape: false)
            ->assertSee('kgm');
    }

    /** And what is chosen there is what the item is measured in. */
    public function test_a_second_hand_item_keeps_the_unit_it_was_given(): void
    {
        Setting::put('units', "kgm\nkarton");

        $this->actingAs($this->user)->post(route('second-hand.store'), [
            'name' => 'Dell Latitude 5420',
            'seller_name' => 'Aram Jalal',
            'category_id' => Category::firstOrFail()->id,
            'unit' => 'karton',
            'cost' => 400_000,
            'sale_price' => 550_000,
            'bought_at' => today()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('karton', Product::where('name', 'Dell Latitude 5420')->firstOrFail()->unit,
            'the second-hand screen threw away the unit the shopkeeper chose');
    }
}
