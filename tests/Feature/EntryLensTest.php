<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Typing an amount through a lens, on the screens Soran asked for last.
 *
 * `MoneyEntryTest` proves the mechanism on expenses. This proves the mechanism
 * reached everywhere else: payments, product prices, stock adjustments,
 * services, second-hand.
 *
 * ⚠️ **Nearly every test here is the untouched-field rule.** That is not
 * repetition for its own sake — each screen wires `MoneyInput::fromRequest` up
 * itself, and each one can forget to pass the record it is editing. Forgetting
 * costs a few units per save, silently, on figures nobody typed: a batch cost
 * FIFO will draw from for months, a payment that reverses and re-posts a ledger
 * row, a product price on every future sale.
 */
class EntryLensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** The same person, with the lens set in the database rather than in memory. */
    private function looking(string $code): User
    {
        User::whereKey($this->admin->getKey())->update(['display_currency' => $code]);

        return User::findOrFail($this->admin->getKey());
    }

    private function product(int $purchase = 10_000, int $sale = 15_000): Product
    {
        return Product::create([
            'name' => 'Cable',
            'sku' => 'CBL-'.random_int(1000, 9999),
            'category_id' => Category::firstOrFail()->id,
            'unit' => 'pcs',
            'purchase_price' => $purchase,
            'sale_price' => $sale,
        ]);
    }

    /** What a box drawn at this figure would be holding, under the lens. */
    private function shown(int $stored, string $code = 'USD'): string
    {
        return Money::plain($stored, Currency::where('code', $code)->firstOrFail());
    }

    // ---- Product prices --------------------------------------------------

    /** ⚠️ Renaming a product must not move either of its prices. */
    public function test_renaming_a_product_through_a_lens_leaves_its_prices_alone(): void
    {
        $product = $this->product();

        $this->actingAs($this->looking('USD'))
            ->put(route('products.update', $product), [
                'name' => 'Cable, braided',
                'category_id' => $product->category_id,
                'unit' => 'pcs',
                'purchase_price' => $this->shown(10_000),
                'purchase_price_shown' => $this->shown(10_000),
                'sale_price' => $this->shown(15_000),
                'sale_price_shown' => $this->shown(15_000),
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors()->assertRedirect();

        $product->refresh();

        $this->assertSame('Cable, braided', $product->name);
        $this->assertSame(10_000, $product->purchase_price, 'the cost moved');
        $this->assertSame(15_000, $product->sale_price, 'the price moved');
    }

    /** And a price somebody did type is converted, as it must be. */
    public function test_a_price_that_was_typed_is_converted(): void
    {
        $product = $this->product();

        $this->actingAs($this->looking('USD'))
            ->put(route('products.update', $product), [
                'name' => 'Cable',
                'category_id' => $product->category_id,
                'unit' => 'pcs',
                'purchase_price' => $this->shown(10_000),
                'purchase_price_shown' => $this->shown(10_000),
                'sale_price' => '20',
                'sale_price_shown' => $this->shown(15_000),
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(26_400, $product->fresh()->sale_price);
        $this->assertSame(10_000, $product->fresh()->purchase_price);
    }

    /**
     * ⚠️ A reader whose cost is masked never moves the cost either.
     *
     * They are shown `*****` instead of a field, so nothing is posted and
     * `prepareForValidation` puts the stored figure back. Under a lens that
     * figure is a dinar count arriving in a request the screen is reading as
     * dollars — without the companion field beside it, saving would multiply
     * the cost by the rate.
     */
    public function test_a_masked_reader_saving_under_a_lens_does_not_multiply_the_cost(): void
    {
        $product = $this->product();

        $masked = User::factory()->create([
            'display_currency' => 'USD',
            'role' => 'user',
            'cost_visibility' => User::COST_HIDDEN,
        ]);
        $masked->permissions()->sync(
            Permission::whereIn('key', ['products.edit', 'products.view'])->pluck('id'),
        );

        $this->actingAs($masked)
            ->put(route('products.update', $product), [
                'name' => 'Cable',
                'category_id' => $product->category_id,
                'unit' => 'pcs',
                'sale_price' => $this->shown(15_000),
                'sale_price_shown' => $this->shown(15_000),
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(10_000, $product->fresh()->purchase_price);
    }

    // ---- Payments --------------------------------------------------------

    private function aPayment(): Payment
    {
        $purchase = app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Erbil Wholesale']),
            lines: [['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 30_000]],
            user: $this->admin,
            purchaseDate: today(),
        );

        $this->actingAs($this->admin)->post(route('payments.store'), [
            'payable_type' => 'purchase',
            'payable_id' => $purchase->id,
            'amount' => 20_000,
            'direction' => Payment::DIRECTION_OUT,
            'payment_method' => 'cash',
            'paid_at' => today()->toDateString(),
        ])->assertRedirect();

        return Payment::latest('id')->firstOrFail();
    }

    /** ⚠️ Correcting a payment's date must not correct its amount too. */
    public function test_changing_a_payments_date_through_a_lens_leaves_the_amount_alone(): void
    {
        $payment = $this->aPayment();

        $this->actingAs($this->looking('USD'))
            ->put(route('payments.update', $payment), [
                'amount' => $this->shown(20_000),
                'amount_shown' => $this->shown(20_000),
                'direction' => $payment->direction,
                'payment_method' => 'bank',
                'paid_at' => today()->subDay()->toDateString(),
            ])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(20_000, $payment->fresh()->amount);
    }

    /** A payment typed in dollars stores dinars. */
    public function test_a_payment_typed_in_dollars_stores_dinars(): void
    {
        $purchase = Purchase::firstOr(fn () => app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Erbil Wholesale']),
            lines: [['product_id' => $this->product()->id, 'quantity' => 1, 'unit_price' => 30_000]],
            user: $this->admin,
            purchaseDate: today(),
        ));

        $this->actingAs($this->looking('USD'))->post(route('payments.store'), [
            'payable_type' => 'purchase',
            'payable_id' => $purchase->id,
            'amount' => '10.00',
            'direction' => Payment::DIRECTION_OUT,
            'payment_method' => 'cash',
            'paid_at' => today()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(13_200, Payment::latest('id')->firstOrFail()->amount);
    }

    // ---- Stock adjustments ----------------------------------------------

    /**
     * ⚠️ Correcting an adjustment's reason must not move its batch cost.
     *
     * This one is the worst of the set: `stock_adjustments.unit_cost` becomes a
     * FIFO layer, so a cost that drifts by a rounding drifts every COGS figure
     * drawn from that layer for as long as it lasts.
     */
    public function test_changing_an_adjustments_reason_through_a_lens_leaves_the_cost_alone(): void
    {
        $product = $this->product();

        $this->actingAs($this->admin)->post(route('stock-adjustments.store'), [
            'product_id' => $product->id,
            'direction' => StockAdjustment::DIRECTION_IN,
            'quantity' => 5,
            'unit_cost' => 9_500,
            'reason' => 'correction',
            'adjusted_at' => today()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $adjustment = StockAdjustment::latest('id')->firstOrFail();

        $this->actingAs($this->looking('USD'))
            ->put(route('stock-adjustments.update', $adjustment), [
                'direction' => StockAdjustment::DIRECTION_IN,
                'quantity' => 5,
                'unit_cost' => $this->shown(9_500),
                'unit_cost_shown' => $this->shown(9_500),
                'reason' => 'miscount',
                'adjusted_at' => today()->toDateString(),
            ])
            ->assertSessionHasNoErrors()->assertRedirect();

        $adjustment->refresh();

        $this->assertSame('miscount', $adjustment->reason);
        $this->assertSame(9_500, $adjustment->unit_cost, 'a FIFO layer moved by a rounding');
    }

    /** An adjustment costed in dollars stores dinars. */
    public function test_an_adjustment_costed_in_dollars_stores_dinars(): void
    {
        $this->actingAs($this->looking('USD'))->post(route('stock-adjustments.store'), [
            'product_id' => $this->product()->id,
            'direction' => StockAdjustment::DIRECTION_IN,
            'quantity' => 2,
            'unit_cost' => '5.00',
            'reason' => 'correction',
            'adjusted_at' => today()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(6_600, StockAdjustment::latest('id')->firstOrFail()->unit_cost);
    }

    // ---- Services --------------------------------------------------------

    /** ⚠️ Renaming a service must not move its price. */
    public function test_renaming_a_service_through_a_lens_leaves_its_price_alone(): void
    {
        $this->actingAs($this->admin)->post(route('services.store'), [
            'name' => 'Screen fitting',
            'sale_price' => 25_000,
        ])->assertSessionHasNoErrors();

        $service = Product::where('kind', Product::KIND_SERVICE)->latest('id')->firstOrFail();

        $this->actingAs($this->looking('USD'))
            ->put(route('services.update', $service), [
                'name' => 'Screen fitting, glass',
                'sale_price' => $this->shown(25_000),
                'sale_price_shown' => $this->shown(25_000),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(25_000, $service->fresh()->sale_price);
    }

    // ---- Second-hand -----------------------------------------------------

    /** Buying a second-hand item in dollars stores dinars in all three columns. */
    public function test_a_second_hand_purchase_typed_in_dollars_stores_dinars(): void
    {
        $this->actingAs($this->looking('USD'))->post(route('second-hand.store'), [
            'name' => 'Used iPhone 12',
            'cost' => '100.00',
            'sale_price' => '150.00',
            'amount_paid' => '50.00',
            'payment_method' => 'cash',
            'seller_name' => 'Karwan',
            'bought_at' => today()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $item = Product::where('name', 'Used iPhone 12')->firstOrFail();

        $this->assertSame(132_000, $item->purchase_price);
        $this->assertSame(198_000, $item->sale_price);
    }
}
