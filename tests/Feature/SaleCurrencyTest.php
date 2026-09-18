<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sale written in another currency — Soran, 2026-09-19.
 *
 * *"if currency on usd change sale page to usd, but in sale page have combo to
 * change again and input to rate"*.
 *
 * ⚠️ **This reverses decision 3b**, which §2b recorded as deliberate: *"you
 * sell across a counter in dinars"*. He sells phones priced in dollars.
 *
 * ⚠️ **What must not change, and these hold it: only base-currency integers are
 * stored.** Whatever the receipt says, the books, FIFO, the ledger and every
 * balance are in dinars. The two `entered_*` columns are a record of what
 * somebody typed, nothing more.
 */
class SaleCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $category = Category::create(['name' => 'Phones']);

        $this->product = Product::create([
            'name' => 'Handset', 'sku' => 'H1', 'category_id' => $category->id, 'unit' => 'pcs',
            'purchase_price' => 100_000, 'sale_price' => 174_000, 'quantity' => 0,
        ]);

        $this->customer = Customer::create(['name' => 'Rebin']);

        // Something on the shelf to sell.
        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S']),
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 100_000]],
            user: $this->admin, purchaseDate: now(),
        );
    }

    /**
     * ⚠️ The heart of it: a dollar receipt still puts dinars in every column
     * the books read. A sale that stored $240 would make every report, every
     * balance and every profit figure since wrong by a factor of the rate.
     */
    public function test_a_dollar_receipt_stores_dinars_in_the_books(): void
    {
        $this->sell(unitPriceBase: 174_000, rate: 1_450, typedUsd: 120.00)->assertRedirect();

        $sale = Sale::latest('id')->firstOrFail();
        $item = $sale->items()->firstOrFail();

        $this->assertSame(174_000, (int) $item->unit_price, 'The books must hold dinars.');
        $this->assertSame(174_000, (int) $sale->total_amount);
        $this->assertSame(1_450, (int) $sale->exchange_rate);

        // And a record of what was actually typed, in that currency's own
        // minor units — 120.00 dollars is 12000 cents.
        $this->assertSame('USD', $item->entered_currency);
        $this->assertSame(12_000, (int) $item->entered_amount);
    }

    /** What the customer owes is the dinar figure, whatever the receipt says. */
    public function test_the_customer_owes_dinars(): void
    {
        $this->sell(unitPriceBase: 174_000, rate: 1_450, typedUsd: 120.00)->assertRedirect();

        $this->assertSame(174_000, (int) $this->customer->refresh()->balance);
    }

    /** A sale in the shop's own money records no rate at all — §2b. */
    public function test_a_dinar_sale_carries_no_rate(): void
    {
        $this->sell(unitPriceBase: 174_000, rate: null, typedUsd: null)->assertRedirect();

        $sale = Sale::latest('id')->firstOrFail();

        $this->assertNull($sale->exchange_rate);
        $this->assertNull($sale->writtenIn());
        $this->assertNull($sale->items()->firstOrFail()->entered_currency);
    }

    /**
     * ⚠️ **One currency on a receipt, not two** — Soran: *"if system on dinar
     * all receipts show on dinar and same for other currencies"*. This is where
     * the receipt parts company with the purchase document, which prints both.
     */
    public function test_a_dollar_receipt_prints_only_dollars(): void
    {
        $this->sell(unitPriceBase: 174_000, rate: 1_450, typedUsd: 120.00)->assertRedirect();

        $sale = Sale::latest('id')->firstOrFail();

        $html = $this->actingAs($this->admin)->get(route('sales.print', $sale))->assertOk()->getContent();

        $this->assertStringContainsString('120.00', $html, 'The receipt should say what it was written in.');
        $this->assertStringNotContainsString('174,000', $html, 'A receipt says one number, not two.');
    }

    /**
     * ⚠️ The rate is read off the DOCUMENT, never the table. The currencies
     * page moves every week; a receipt in a customer's hand does not, and the
     * second figure would be shown to somebody arguing about a refund.
     */
    public function test_moving_the_shops_rate_does_not_change_a_printed_receipt(): void
    {
        $this->sell(unitPriceBase: 174_000, rate: 1_450, typedUsd: 120.00)->assertRedirect();

        $sale = Sale::latest('id')->firstOrFail();

        Currency::where('code', 'USD')->firstOrFail()->update(['rate' => 2_000 * Money::RATE_SCALE]);
        Currency::flushCache();

        $this->assertSame('120.00', $sale->asWritten($sale->total_amount));
    }

    /** The screen opens on the one setting the shop has already answered. */
    public function test_the_till_opens_in_the_currency_the_shop_chose(): void
    {
        Setting::query()->updateOrCreate(['key' => 'purchase_currency'], ['value' => 'USD']);
        cache()->flush();

        $this->actingAs($this->admin)->get(route('sales.create'))
            ->assertOk()
            ->assertViewHas('documentCurrency', 'USD');

        Setting::query()->updateOrCreate(['key' => 'purchase_currency'], ['value' => null]);
        cache()->flush();

        $this->actingAs($this->admin)->get(route('sales.create'))
            ->assertOk()
            ->assertViewHas('documentCurrency', Money::base()->code);
    }

    /**
     * ⚠️ Reopening a dollar sale must show dollars whatever the till opens in
     * now, or its lines come back read as dinars and the money changes on a
     * screen nobody typed in.
     */
    public function test_reopening_a_dollar_sale_shows_dollars(): void
    {
        $this->sell(unitPriceBase: 174_000, rate: 1_450, typedUsd: 120.00)->assertRedirect();

        Setting::query()->updateOrCreate(['key' => 'purchase_currency'], ['value' => null]);
        cache()->flush();

        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->get(route('sales.edit', $sale))
            ->assertOk()
            ->assertViewHas('documentCurrency', 'USD')
            ->assertViewHas('documentRate', 1_450);
    }

    private function sell(int $unitPriceBase, ?int $rate, ?float $typedUsd)
    {
        $line = [
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => $unitPriceBase,
        ];

        if ($typedUsd !== null) {
            $line['entered_currency'] = 'USD';
            $line['entered_amount'] = (int) round($typedUsd * 100);
        }

        return $this->actingAs($this->admin)->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'sale_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'amount_paid' => 0,
            'exchange_rate' => $rate,
            'lines' => [$line],
        ]);
    }
}
