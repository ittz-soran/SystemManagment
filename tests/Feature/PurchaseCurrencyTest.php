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

    /** @param array<int, array<string, mixed>> $lines */
    private function buy(array $lines, ?int $rate = null): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'amount_paid' => 0,
            'exchange_rate' => $rate,
            'lines' => $lines,
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
            ->assertViewHas('cartLines', fn (array $lines) => $lines[0]['currency'] === 'TRY'
                && (float) $lines[0]['enteredAmount'] === 250.0
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
            ->assertViewHas('cartLines', fn (array $lines) => $lines[0]['currency'] === 'JPY'
                && (float) $lines[0]['enteredAmount'] === 500.0);
    }
}
