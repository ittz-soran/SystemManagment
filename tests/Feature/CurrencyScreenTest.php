<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Managing the currencies a shop can type and read in — Section 2b.
 *
 * The interesting tests here are the REFUSALS. Everything else is an ordinary
 * managed list; those are the reason the page needed thinking about at all,
 * because each of them can silently reprice the whole shop.
 */
class CurrencyScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function usd(): Currency
    {
        return Currency::where('code', 'USD')->firstOrFail();
    }

    private function iqd(): Currency
    {
        return Currency::where('code', 'IQD')->firstOrFail();
    }

    // ---- Which currency the books are kept in ---------------------------

    /**
     * ⚠️ THE MOST DANGEROUS BUTTON ON THIS SCREEN, and why it is guarded by a
     * fact rather than a confirmation.
     *
     * Every stored integer counts base-currency minor units. Point
     * `currency_base` at the dollar and 250,000 recorded dinars do not convert
     * — they are REINTERPRETED as $250,000, on every document at once. There is
     * no wording that makes clicking that a reasonable thing to do, so the move
     * is allowed only while nothing has been recorded.
     */
    public function test_the_books_cannot_move_once_anything_has_been_recorded(): void
    {
        $this->aPurchase();

        $this->actingAs($this->admin)
            ->post(route('currencies.base', $this->usd()))
            ->assertRedirect();

        $this->assertSame('IQD', Money::base()->code, 'the books moved with documents in them');
    }

    /** And the screen says so, rather than hiding the button. */
    public function test_the_screen_says_why_the_books_cannot_move(): void
    {
        $this->aPurchase();

        $this->actingAs($this->admin)->get(route('currencies.index'))
            ->assertOk()
            ->assertSee(__('Make base'))
            ->assertSee('disabled', false);
    }

    /** A shop choosing its currency during setup is the case that needs it. */
    public function test_a_shop_with_nothing_recorded_can_choose_its_currency(): void
    {
        $this->actingAs($this->admin)
            ->post(route('currencies.base', $this->usd()))
            ->assertSessionHasNoErrors()->assertRedirect();

        Currency::flushCache();

        $this->assertSame('USD', Money::base()->code);

        // ⚠️ The new base takes the definitional rate: a dollar is one dollar.
        $this->assertSame(100 * Money::RATE_SCALE, $this->usd()->fresh()->rate);

        /*
         * And the old base is switched off with a worked-out rate. 1 IQD is
         * 1/1320 of a dollar — 0.076 of a cent-counting rate — which is a
         * starting point, not an answer, so nothing is priced with it until
         * somebody has looked.
         */
        $iqd = $this->iqd()->fresh();

        $this->assertFalse($iqd->is_active);
        $this->assertSame(76, $iqd->rate);
    }

    // ---- Decimals -------------------------------------------------------

    /**
     * ⚠️ Section 2b's redenomination, from the screen.
     *
     * Changing the base's decimals is what turns 250,000 dinars into 250. No
     * row moves and nothing is written — the stored integer stops counting
     * dinars and starts counting fils — which is exactly why it is safe to
     * offer: setting it back puts every figure where it was.
     */
    public function test_the_base_decimals_change_the_reading_and_nothing_else(): void
    {
        $before = Product::create([
            'name' => 'Cable', 'sku' => 'C9', 'unit' => 'pcs',
            'category_id' => Category::firstOrFail()->id,
            'purchase_price' => 250_000, 'sale_price' => 250_000,
        ]);

        $this->actingAs($this->admin)->put(route('currencies.update', $this->iqd()), [
            'name' => 'Iraqi Dinar',
            'symbol' => 'د.ع',
            'decimals' => 3,
        ])->assertSessionHasNoErrors()->assertRedirect();

        Currency::flushCache();

        // The stored figure has not moved one unit.
        $this->assertSame(250_000, $before->fresh()->purchase_price);

        // Only the reading of it has.
        $this->assertSame('250', Money::format(250_000));

        // And the base's rate follows the decimals it was just given.
        $this->assertSame(1_000 * Money::RATE_SCALE, $this->iqd()->fresh()->rate);
    }

    /** Setting it back puts every figure exactly where it was. */
    public function test_the_redenomination_is_reversible(): void
    {
        foreach ([3, 0] as $places) {
            $this->actingAs($this->admin)->put(route('currencies.update', $this->iqd()), [
                'name' => 'Iraqi Dinar', 'symbol' => 'د.ع', 'decimals' => $places,
            ])->assertSessionHasNoErrors();

            Currency::flushCache();
        }

        $this->assertSame('250,000', Money::format(250_000));
    }

    // ---- Removing one ---------------------------------------------------

    /** A currency nothing points at can simply go. */
    public function test_a_currency_nothing_uses_can_be_removed(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('currencies.destroy', $this->usd()))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseMissing('currencies', ['code' => 'USD']);
    }

    /**
     * ⚠️ But not one a document names.
     *
     * A purchase records the code it was invoiced in, and a code with no row
     * behind it prints as a blank on the invoice that needs it most.
     */
    public function test_a_currency_a_document_names_is_kept(): void
    {
        $this->aPurchase(currency: 'USD');

        $this->actingAs($this->admin)
            ->delete(route('currencies.destroy', $this->usd()))
            ->assertRedirect();

        $this->assertDatabaseHas('currencies', ['code' => 'USD']);
    }

    /** Nor one somebody is reading in. */
    public function test_a_currency_somebody_reads_in_is_kept(): void
    {
        User::whereKey($this->admin->getKey())->update(['display_currency' => 'USD']);

        $this->actingAs($this->admin)
            ->delete(route('currencies.destroy', $this->usd()))
            ->assertRedirect();

        $this->assertDatabaseHas('currencies', ['code' => 'USD']);
    }

    /** And never the one the books are kept in. */
    public function test_the_base_cannot_be_removed(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('currencies.destroy', $this->iqd()))
            ->assertRedirect();

        $this->assertDatabaseHas('currencies', ['code' => 'IQD']);
    }

    /** One recorded purchase, so the books are no longer empty. */
    private function aPurchase(?string $currency = null): void
    {
        $product = Product::create([
            'name' => 'Cable', 'sku' => 'C'.random_int(100, 999), 'unit' => 'pcs',
            'category_id' => Category::firstOrFail()->id,
            'purchase_price' => 1_000, 'sale_price' => 1_500,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S']),
            lines: [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 10_000,
                'entered_currency' => $currency ?? 'IQD',
                'entered_amount' => $currency ? 1_000 : null,
            ]],
            user: $this->admin,
            purchaseDate: today(),
            exchangeRate: $currency ? 1_320 : null,
        );
    }

    public function test_it_lists_what_the_shop_can_type_in(): void
    {
        $this->actingAs($this->admin)->get(route('currencies.index'))
            ->assertOk()
            ->assertSee('IQD')
            ->assertSee('USD')
            ->assertSee('US Dollar')
            // §6b's rate, carried across rather than invented.
            ->assertSee('1320');
    }

    public function test_the_base_is_marked_and_has_no_rate_of_its_own(): void
    {
        $this->actingAs($this->admin)->get(route('currencies.index'))
            ->assertOk()
            ->assertSee(__('Base'));

        $this->assertSame('IQD', Money::base()->code);
    }

    public function test_a_currency_can_be_added(): void
    {
        $this->actingAs($this->admin)->post(route('currencies.store'), [
            'code' => 'eur', 'name' => 'Euro', 'symbol' => '€',
            'decimals' => 2, 'rate' => '1450.5',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $eur = Currency::where('code', 'EUR')->firstOrFail();

        // Upper-cased on the way in, because a supplier's invoice says EUR.
        $this->assertSame('EUR', $eur->code);
        $this->assertSame(1_450_500, $eur->rate);
        $this->assertSame('1450.5', $eur->rateAsTyped());

        // And it converts, which is the whole point of adding it.
        $this->assertSame(14_505, Money::parse('10', $eur->fresh()));
    }

    public function test_a_rate_that_is_not_a_number_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('currencies.store'), [
            'code' => 'EUR', 'name' => 'Euro', 'decimals' => 2, 'rate' => 'soon',
        ])->assertSessionHasErrors('rate');

        $this->assertNull(Currency::where('code', 'EUR')->first());
    }

    public function test_a_rate_of_zero_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('currencies.store'), [
            'code' => 'EUR', 'name' => 'Euro', 'decimals' => 2, 'rate' => '0',
        ])->assertSessionHasErrors('rate');
    }

    public function test_the_same_code_cannot_be_added_twice(): void
    {
        $this->actingAs($this->admin)->post(route('currencies.store'), [
            'code' => 'USD', 'name' => 'Another dollar', 'decimals' => 2, 'rate' => '1300',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, Currency::where('code', 'USD')->count());
    }

    public function test_a_rate_can_be_changed_and_takes_effect_at_once(): void
    {
        $usd = $this->usd();

        $this->assertSame(660_000, Money::parse('500', $usd));

        $this->actingAs($this->admin)->put(route('currencies.update', $usd), [
            'name' => 'US Dollar', 'symbol' => '$', 'rate' => '1350', 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(675_000, Money::parse('500', $this->usd()));
    }

    /**
     * ⚠️ Refusal one: the base currency has no rate anybody may set.
     *
     * It is `10^decimals` by definition — a dinar is one dinar. Letting
     * somebody type 1,320 into it would divide every figure in the shop by
     * 1,320 on the next page load.
     */
    public function test_the_base_currency_keeps_its_own_rate_whatever_is_posted(): void
    {
        $iqd = $this->iqd();
        $was = $iqd->rate;

        $this->actingAs($this->admin)->put(route('currencies.update', $iqd), [
            'name' => 'Iraqi Dinar', 'rate' => '1320', 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($was, $this->iqd()->rate, 'The books were repriced by a rate box.');
        $this->assertSame('250,000', Money::format(250_000));
    }

    /**
     * ⚠️ Refusal two: the base cannot be switched off.
     *
     * A shop with no currency for its own books has no way to write a figure
     * at all — including on the page that would switch it back on.
     */
    public function test_the_base_currency_cannot_be_switched_off(): void
    {
        $this->actingAs($this->admin)->put(route('currencies.update', $this->iqd()), [
            'name' => 'Iraqi Dinar',
            // The checkbox posts nothing when unticked, which is exactly how
            // this would be attempted.
        ])->assertRedirect();

        $this->assertTrue($this->iqd()->is_active);
    }

    /** A currency the shop has stopped using is switched off, not deleted. */
    public function test_a_currency_can_be_switched_off(): void
    {
        $this->actingAs($this->admin)->put(route('currencies.update', $this->usd()), [
            'name' => 'US Dollar', 'rate' => '1320',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($this->usd()->is_active);
        $this->assertNotNull($this->usd(), 'A currency in use on old records was deleted.');
    }

    /** ⚠️ The rate must survive being typed, stored and read back. */
    public function test_a_fractional_rate_survives_the_round_trip(): void
    {
        $this->actingAs($this->admin)->put(route('currencies.update', $this->usd()), [
            'name' => 'US Dollar', 'rate' => '1320.125', 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1_320_125, $this->usd()->rate);
        $this->assertSame('1320.125', $this->usd()->rateAsTyped());
    }

    /** A thousands separator is what a person pastes out of a spreadsheet. */
    public function test_a_rate_typed_with_a_separator_is_understood(): void
    {
        $this->actingAs($this->admin)->put(route('currencies.update', $this->usd()), [
            'name' => 'US Dollar', 'rate' => '1,350', 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1_350_000, $this->usd()->rate);
    }

    /** Section 8c: the whole of settings is behind one permission. */
    public function test_a_reader_without_settings_cannot_reach_it(): void
    {
        $staff = User::create([
            'name' => 'Till', 'email' => 'till@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'sales.view')->pluck('id')->all());

        $this->actingAs($staff)->get(route('currencies.index'))->assertForbidden();

        $this->actingAs($staff)->put(route('currencies.update', $this->usd()), [
            'name' => 'Theirs', 'rate' => '1',
        ])->assertForbidden();

        $this->assertSame(1_320_000, $this->usd()->rate);
    }

    /** Settings names the page, so it can be found without knowing the URL. */
    public function test_settings_links_to_it(): void
    {
        $this->actingAs($this->admin)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee(route('currencies.index'), false);
    }
}
