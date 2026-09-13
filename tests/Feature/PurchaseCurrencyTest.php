<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\HeldCart;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Typing a purchase in another currency — Section 2b, on the cart.
 *
 * Section 6b built this once, for dollars: a rate box on the invoice and a
 * per-line IQD/USD toggle. That was hard-coded in three places at once — the
 * enum on `purchase_items.entered_currency`, a `/100` that assumed cents, and
 * two `<option>` tags. A shop that keeps euros or lira could choose them in
 * Settings and then not type in them anywhere.
 *
 * What has NOT changed, and these tests hold it: only base-currency integers
 * are ever stored. `entered_currency` and `entered_amount` are a record of what
 * somebody typed, so the document can show it back and an edit can reopen the
 * box the way they left it.
 */
class PurchaseCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->supplier = Supplier::create(['name' => 'Erbil Electronics']);

        $this->product = Product::create([
            'name' => 'USB-C cable',
            'sku' => 'CBL-001',
            'category_id' => Category::firstOrFail()->id,
            'unit' => 'pcs',
            'purchase_price' => 1_000,
            'sale_price' => 1_500,
        ]);
    }

    /** A currency the seeder does not ship, so nothing about it is assumed. */
    private function lira(int $rate = 40): Currency
    {
        return Currency::create([
            'code' => 'TRY',
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'decimals' => 2,
            'rate' => $rate * Money::RATE_SCALE,
            'is_active' => true,
        ]);
    }

    /** One with NO decimals — the case a hard-coded hundred gets wrong. */
    private function yen(int $rate = 9): Currency
    {
        return Currency::create([
            'code' => 'JPY',
            'name' => 'Japanese Yen',
            'symbol' => '¥',
            'decimals' => 0,
            'rate' => $rate * Money::RATE_SCALE,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function buy(array $lines, ?int $rate = null, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'amount_paid' => 0,
            'exchange_rate' => $rate,
            'lines' => $lines,
            ...$extra,
        ]);
    }

    // ---- The column ------------------------------------------------------

    /**
     * ⚠️ The blocker the migration exists for.
     *
     * `entered_currency` was `enum('IQD','USD')`. MariaDB does not refuse a
     * value outside an enum with an error a controller can catch under a
     * default install — it stores the empty string and warns. Either way, a
     * shop that added lira in Settings could not record one, and the failure
     * would land on the shopkeeper as a price that came back wrong.
     */
    public function test_a_line_can_be_typed_in_a_currency_the_old_enum_never_knew(): void
    {
        $this->lira();

        // 250 lira at 40 dinars each.
        $this->buy([[
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 10_000,
            'entered_currency' => 'TRY',
            'entered_amount' => 25_000,
        ]], rate: 40)->assertRedirect();

        $item = Purchase::sole()->items()->sole();

        $this->assertSame('TRY', $item->entered_currency);
        $this->assertSame(25_000, $item->entered_amount);

        // Only dinars are stored. The line total is quantity × the base price.
        $this->assertSame(10_000, $item->unit_price);
        $this->assertSame(20_000, Purchase::sole()->total_amount);
    }

    /** The column really is wide enough now, not merely a longer enum. */
    public function test_the_column_takes_any_code_a_shop_might_add(): void
    {
        $type = Schema::getColumnType('purchase_items', 'entered_currency');

        $this->assertContains($type, ['string', 'varchar'], "got {$type}");
    }

    // ---- Reading a saved line back --------------------------------------

    /**
     * ⚠️ Divided by THAT currency's minor units, not by a hard-coded hundred.
     *
     * A yen has no decimals, so its stored figure and its typed figure are the
     * same number. The old `/100` turned ¥500 into ¥5 on the way back into the
     * box — and the shopkeeper, seeing 5, would correct it to 500 and pay a
     * hundred times over.
     */
    public function test_a_currency_with_no_decimals_comes_back_at_the_amount_typed(): void
    {
        $this->yen();

        $this->buy([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 4_500,
            'entered_currency' => 'JPY',
            'entered_amount' => 500,
        ]], rate: 9)->assertRedirect();

        $item = Purchase::sole()->items()->sole();

        $this->assertSame(500, $item->typedAmount());
        $this->assertSame('JPY', $item->typedIn()?->code);
    }

    /** Two decimals still divide by a hundred — the dollar case is unchanged. */
    public function test_a_two_decimal_currency_still_comes_back_in_units_and_cents(): void
    {
        $this->lira();

        $this->buy([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 10_000,
            'entered_currency' => 'TRY',
            'entered_amount' => 25_050,
        ]], rate: 40)->assertRedirect();

        $this->assertSame(250.5, Purchase::sole()->items()->sole()->typedAmount());
    }

    /** A base-currency line was never typed in anything else. */
    public function test_a_base_currency_line_reports_no_typed_currency(): void
    {
        $this->buy([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 10_000,
            'entered_currency' => Money::base()->code,
        ]])->assertRedirect();

        $item = Purchase::sole()->items()->sole();

        $this->assertNull($item->typedIn());
        $this->assertSame(0, $item->typedAmount());
    }

    // ---- One currency for the whole document ----------------------------

    /**
     * ⚠️ The screen has ONE currency, not one per line.
     *
     * Soran, 2026-09-13, seeing the per-line toggle: *"in same purchase have
     * one type currency for all lines, not one usd and one dinar… just have
     * invoice currency combo to select and input rate change directly in
     * purchase"*. A supplier invoices in one currency; a row that could differ
     * from the row above it was a way to get an invoice wrong, not a feature.
     */
    public function test_the_cart_has_no_per_line_currency_control(): void
    {
        $this->lira();

        $html = $this->actingAs($this->admin)->get(route('purchases.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-role="currency"', $html);
        $this->assertStringContainsString('id="document_currency"', $html);
    }

    /**
     * ⚠️ Picking a currency shows the whole screen in it.
     *
     * Soran again: *"while change invoice currency not change prices"* — the
     * first build left every figure in dinars and only took dollars in one
     * box, which told him nothing about what he was buying. Every money box
     * and every total is drawn through the invoice currency, and the hidden
     * field beside each one carries the base integer the form posts.
     */
    public function test_every_money_box_on_the_screen_posts_base_units_through_a_hidden_field(): void
    {
        $html = $this->actingAs($this->admin)->get(route('purchases.create'))->assertOk()->getContent();

        // The visible boxes are unnamed: they hold whatever the invoice
        // currency is, and are nobody's business but the screen's.
        $this->assertStringContainsString('id="discount_shown"', $html);
        $this->assertStringContainsString('id="amount_paid_shown"', $html);
        $this->assertStringNotContainsString('name="discount_amount" class', $html);

        // What the form posts is the base-currency figure.
        $this->assertStringContainsString('type="hidden" name="discount_amount"', $html);
        $this->assertStringContainsString('type="hidden" name="amount_paid"', $html);
    }

    /**
     * A whole invoice typed in dollars stores dinars, everywhere.
     *
     * Not only the line prices: the discount and what was paid are typed in
     * the invoice currency too, and a screen where the price is dollars but
     * the discount is dinars is a way to lose money quietly.
     */
    public function test_a_dollar_invoice_stores_dinars_in_every_column(): void
    {
        // $9.93 and $10.00 at 1,550, less a 50¢ discount, paid in full.
        $this->buy([
            [
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 15_392,
                'entered_currency' => 'USD',
                'entered_amount' => 993,
            ],
            [
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 15_500,
                'entered_currency' => 'USD',
                'entered_amount' => 1_000,
            ],
        ], rate: 1_550, extra: [
            'discount_amount' => 775,
            'amount_paid' => 30_117,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $purchase = Purchase::sole();

        $this->assertSame(30_892, $purchase->total_amount);
        $this->assertSame(775, $purchase->discount_amount);
        $this->assertSame(1_550, $purchase->exchange_rate);
        $this->assertSame(30_117, $purchase->amountPaid());
    }

    // ---- The entry screen -----------------------------------------------

    /** The invoice-currency choice is the shop's list, not two hard-coded tags. */
    public function test_the_cart_offers_every_currency_the_shop_keeps(): void
    {
        $this->lira();
        $this->yen();

        $html = $this->actingAs($this->admin)->get(route('purchases.create'))->assertOk()->getContent();

        foreach (['IQD', 'USD', 'TRY', 'JPY'] as $code) {
            $this->assertStringContainsString('value="'.$code.'"', $html, $code);
        }
    }

    /** A currency switched off in Settings is not a currency you can type in. */
    public function test_a_currency_that_is_switched_off_is_not_offered_and_is_refused(): void
    {
        $this->lira()->update(['is_active' => false]);

        $html = $this->actingAs($this->admin)->get(route('purchases.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Turkish Lira', $html);

        $this->buy([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 10_000,
            'entered_currency' => 'TRY',
            'entered_amount' => 25_000,
        ]], rate: 40)->assertSessionHasErrors('lines.0.entered_currency');
    }

    /**
     * The rate box starts at what Settings holds, in WHOLE base units.
     *
     * `purchases.exchange_rate` has always been a whole number of dinars per
     * one foreign unit, and every rate already recorded means that. The
     * currencies table can carry 1,320.125 for reading; this box cannot, and
     * widening it would change the meaning of the column underneath.
     */
    public function test_the_rate_box_starts_at_the_saved_rate(): void
    {
        Currency::where('code', 'USD')->firstOrFail()->update(['rate' => 1_450 * Money::RATE_SCALE]);
        Currency::flushCache();

        $this->actingAs($this->admin)
            ->get(route('purchases.create'))
            ->assertOk()
            ->assertViewHas('documentRate', 1_450);
    }

    /** Editing reopens the invoice in the currency its lines were typed in. */
    public function test_editing_reopens_the_invoice_in_the_currency_it_was_written_in(): void
    {
        $this->lira();

        $this->buy([[
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 10_000,
            'entered_currency' => 'TRY',
            'entered_amount' => 25_000,
        ]], rate: 40)->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('purchases.edit', Purchase::sole()))
            ->assertOk()
            ->assertViewHas('documentCurrency', 'TRY')
            ->assertViewHas('documentRate', 40)
            ->assertViewHas('cartLines', fn (array $lines) => (float) $lines[0]['typed'] === 250.0
                && $lines[0]['price'] === 10_000);
    }

    /**
     * ⚠️ Switching a currency off must not strand the purchases written in it.
     *
     * Settings decides what a NEW document may name. An old one already says
     * what it says, and somebody opening it to fix a quantity must be able to
     * save — otherwise a currency switched off in January locks every invoice
     * from before it, and the way out is switching it back on.
     */
    public function test_a_purchase_stays_editable_after_its_currency_is_switched_off(): void
    {
        $lira = $this->lira();

        $this->buy([[
            'product_id' => $this->product->id,
            'quantity' => 2,
            'unit_price' => 10_000,
            'entered_currency' => 'TRY',
            'entered_amount' => 25_000,
        ]], rate: 40)->assertRedirect();

        $lira->update(['is_active' => false]);
        Currency::flushCache();

        $purchase = Purchase::sole();

        // It still opens, on the currency it was written in.
        $this->actingAs($this->admin)
            ->get(route('purchases.edit', $purchase))
            ->assertOk()
            ->assertViewHas('documentCurrency', 'TRY');

        // And it still saves — a quantity corrected, nothing else touched.
        $this->actingAs($this->admin)->put(route('purchases.update', $purchase), [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => today()->toDateString(),
            'exchange_rate' => 40,
            'document_currency' => 'TRY',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 3,
                'unit_price' => 10_000,
                'entered_currency' => 'TRY',
                'entered_amount' => 25_000,
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(3, Purchase::sole()->items()->sole()->quantity);
    }

    /**
     * ⚠️ A line nobody typed a foreign figure for comes back as null.
     *
     * This is the untouched-field rule of Section 2b, on the cart. The screen
     * draws such a line's box by converting its stored price, and correcting
     * the rate afterwards leaves that price exactly where it is. Hand it a
     * figure instead and a rate correction would rewrite a price nobody
     * touched: 5,000 dinars at 1,550 shows $3.23, and $3.23 at 1,600 is 5,168.
     */
    public function test_a_line_nobody_typed_a_figure_for_is_not_anchored_to_a_rounded_one(): void
    {
        $this->lira();

        $this->buy([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 5_000,
            'entered_currency' => 'TRY',
            // No entered_amount: the shopkeeper accepted the converted price
            // the screen showed rather than typing one.
        ]], rate: 40)->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('purchases.edit', Purchase::sole()))
            ->assertOk()
            ->assertViewHas('cartLines', fn (array $lines) => $lines[0]['typed'] === null
                && $lines[0]['price'] === 5_000);
    }

    // ---- What the document shows ----------------------------------------

    /** The saved purchase says what was typed, beside the figure it stored. */
    public function test_the_document_shows_the_amount_that_was_typed(): void
    {
        $this->yen();

        $this->buy([[
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 4_500,
            'entered_currency' => 'JPY',
            'entered_amount' => 500,
        ]], rate: 9)->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('purchases.show', Purchase::sole()))
            ->assertOk()
            ->assertSee('entered as 500 ¥');
    }

    // ---- A cart put down and picked up ----------------------------------

    /** A held line in a no-decimals currency comes back at the amount typed. */
    public function test_a_held_line_in_a_currency_with_no_decimals_survives_the_round_trip(): void
    {
        $this->yen();

        $this->actingAs($this->admin)->postJson(route('held-carts.store'), [
            'type' => 'purchase',
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 4_500,
                'entered_currency' => 'JPY',
                'entered_amount' => 500,
            ]],
        ])->assertOk();

        $this->actingAs($this->admin)
            ->get(route('purchases.create', ['held' => HeldCart::sole()->id]))
            ->assertOk()
            ->assertViewHas('cartLines', fn (array $lines) => (float) $lines[0]['typed'] === 500.0);
    }
}
