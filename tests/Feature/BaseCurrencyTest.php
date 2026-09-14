<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which currency the books are kept in, and how it stopped being a guess.
 *
 * **Soran's shop, 2026-09-14.** He had deleted the seeded `IQD` row and made
 * his own `IRQ`, so `settings.currency_base` named a code with no row behind
 * it. `Money::base()` fell back to "whichever currency sorts first", which was
 * IRQ — right by luck. Then he added GBP, which sorts before IRQ, and **the
 * books silently moved to the pound**: every stored figure re-read with two
 * decimal places, on every screen, with nothing asking him.
 *
 * The dashboard read `139,528.64 IQD` — a pound figure wearing a dinar label,
 * because `money()` also hard-coded `IQD` as its suffix rather than asking the
 * base what it is called.
 *
 * ⚠️ Nothing was corrupted. The stored integers never moved; only the reading
 * of them did. But a shopkeeper acts on the reading.
 */
class BaseCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** Soran's shop: the seeded base gone, his own in its place. */
    private function hisShop(): void
    {
        Currency::where('code', 'IQD')->delete();

        Currency::create([
            'code' => 'IRQ', 'name' => 'Iraq dinar', 'symbol' => 'د.ع',
            'decimals' => 0, 'rate' => 1 * Money::RATE_SCALE, 'is_active' => true,
        ]);

        Currency::flushCache();
    }

    // ---- The theft -------------------------------------------------------

    /**
     * ⚠️ THE BUG. Adding a currency must never move the books.
     *
     * `$all[$code] ?? reset($all)` picks whichever row sorts first when the
     * named one is missing. GBP sorts before IRQ, so creating it took the
     * books.
     */
    public function test_adding_a_currency_never_changes_which_one_the_books_are_in(): void
    {
        $this->hisShop();

        $before = Money::base()->code;

        $this->actingAs($this->admin)->post(route('currencies.store'), [
            'code' => 'GBP', 'name' => 'UK Pound', 'symbol' => '£',
            'decimals' => 2, 'rate' => '1995.48',
        ])->assertSessionHasNoErrors();

        Currency::flushCache();

        $this->assertSame($before, Money::base()->code,
            'adding a currency moved the books to it');
        $this->assertNotSame('GBP', Money::base()->code);
    }

    /** Not even when the setting names a currency that is not there at all. */
    public function test_a_missing_base_is_never_answered_with_whichever_sorts_first(): void
    {
        $this->hisShop();

        // The setting still says IQD, and there is no IQD.
        $this->assertSame('IQD', setting('currency_base'));

        Currency::create([
            'code' => 'AAA', 'name' => 'Sorts first', 'symbol' => null,
            'decimals' => 2, 'rate' => 5 * Money::RATE_SCALE, 'is_active' => true,
        ]);

        Currency::flushCache();

        $this->assertNotSame('AAA', Money::base()->code,
            'a brand new currency became the books by sorting first');
    }

    /**
     * And a base with no row still counts what it counted.
     *
     * Section 2b's `currency_minor_per_major` is what the integers were stored
     * against; a missing row must not silently re-read them at two places.
     */
    public function test_a_base_with_no_row_keeps_the_decimals_the_books_were_written_at(): void
    {
        $this->hisShop();

        $this->assertSame(0, Money::base()->decimals);
        $this->assertSame('13,952,864', Money::format(13_952_864));
    }

    // ---- Getting out of it again ----------------------------------------

    /**
     * ⚠️ The whole of Soran's morning, and the way out.
     *
     * His shop, exactly: `IQD` deleted, his own `IRQ` in its place, GBP added,
     * and two purchases on the books. Every figure had been reading as pounds
     * at two places since the moment GBP was saved.
     */
    public function test_a_shop_whose_base_went_missing_can_put_it_back(): void
    {
        $this->hisShop();

        Currency::create([
            'code' => 'GBP', 'name' => 'UK Pound', 'symbol' => '£',
            'decimals' => 2, 'rate' => 1_995_480, 'is_active' => true,
        ]);

        Currency::where('code', 'USD')->update(['rate' => 1_550 * Money::RATE_SCALE]);
        Currency::flushCache();

        // 1. The reading is right again the moment the fallback stops guessing:
        //    whole units, which is what the integers were stored against.
        $this->assertSame(0, Money::base()->decimals);
        $this->assertSame('13,952,864', Money::format(13_952_864));

        // 2. The screen says what is wrong rather than leaving him to find it.
        $this->actingAs($this->admin)->get(route('currencies.index'))
            ->assertOk()
            ->assertSee(__('The books are set to :code, and there is no such currency here.', ['code' => 'IQD']));

        // 3. And he can point them at the row he actually made.
        $this->actingAs($this->admin)
            ->post(route('currencies.base', Currency::where('code', 'IRQ')->firstOrFail()))
            ->assertSessionHasNoErrors()->assertRedirect();

        Currency::flushCache();

        $this->assertSame('IRQ', Money::base()->code);
        $this->assertSame('13,952,864', Money::format(13_952_864));

        /*
         * ⚠️ And his dollar rate survives. `IRQ` is the same money the books
         * were always written in — one of it is one base unit — so 1,550 per
         * dollar is still 1,550 per dollar. Switching it off here would have
         * been a second thing for him to notice and repair.
         */
        $usd = Currency::where('code', 'USD')->firstOrFail();

        $this->assertTrue($usd->is_active);
        $this->assertSame(1_550 * Money::RATE_SCALE, (int) $usd->rate);
    }

    /** And the figure is labelled with his own symbol once it is back. */
    public function test_the_recovered_shop_labels_its_figures_with_its_own_mark(): void
    {
        $this->hisShop();

        $this->actingAs($this->admin)
            ->post(route('currencies.base', Currency::where('code', 'IRQ')->firstOrFail()))
            ->assertSessionHasNoErrors();

        Currency::flushCache();

        $this->assertSame('13,952,864 د.ع', money(13_952_864));
    }

    // ---- The label -------------------------------------------------------

    /**
     * ⚠️ A figure says what it actually is.
     *
     * `money()` wrote the literal `IQD` after every unlensed figure, so a shop
     * whose books are in pounds read `139,528.64 IQD`.
     */
    public function test_a_figure_is_labelled_with_the_currency_the_books_are_in(): void
    {
        Currency::where('code', 'IQD')->update(['code' => 'GBP', 'name' => 'UK Pound', 'symbol' => '£', 'decimals' => 2]);
        Setting::put('currency_base', 'GBP');
        Currency::flushCache();

        $this->assertStringContainsString('£', money(13_952_864));
        $this->assertStringNotContainsString('IQD', money(13_952_864));
    }

    /** A shop that set no symbol is still labelled — with its code. */
    public function test_a_currency_with_no_symbol_is_labelled_with_its_code(): void
    {
        $this->assertSame('IQD', Money::base()->mark());
        $this->assertStringContainsString('IQD', money(1_000));
    }
}
