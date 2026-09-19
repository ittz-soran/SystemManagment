<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\User;
use App\Support\Flags;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Language and currency in one menu — Soran, 2026-09-18.
 *
 * *"move read in/type in currency to near languages… but have flag and select
 * both languages and currency"*.
 *
 * The currency half used to be `<x-currency-lens>` on thirty-two screens, each
 * in its own actions bar. One control that follows the reader is what he asked
 * for and is less to look at.
 */
class PreferencesMenuTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /**
     * ⚠️ **The flag bug, and it drew a real one.**
     *
     * The first version took the first two letters of a currency code as the
     * country. Soran's own shop had its dinar coded `IRQ` — and `IR` is Iran,
     * so "Iraq dinar" sat in the menu under an Iranian flag. A rule that is
     * right often enough to look like it works and wrong in the one case in
     * front of us.
     */
    public function test_a_currency_never_gets_another_countrys_flag(): void
    {
        // Iraq's flag carries three green stars; Iran's carries a red emblem.
        $this->assertStringContainsString('#007a3d', Flags::forCurrency('IRQ'), 'IRQ is the Iraqi dinar, not the Iranian rial.');
        $this->assertStringContainsString('#007a3d', Flags::forCurrency('IQD'));
        $this->assertStringContainsString('#239f40', Flags::forCurrency('IRR'));
        $this->assertNotSame(Flags::forCurrency('IQD'), Flags::forCurrency('IRR'));

        // A currency nobody drew gets nothing rather than a guess.
        foreach (['TRY', 'JPY', 'XYZ', 'ZZ'] as $unknown) {
            $this->assertNull(Flags::forCurrency($unknown), $unknown.' should have no flag rather than a wrong one.');
        }
    }

    /** ⚠️ Soran's two answers, which no rule derives: a language is not a country. */
    public function test_kurdish_and_arabic_carry_the_flags_he_asked_for(): void
    {
        // The Kurdistan flag is the only one carrying the sun's gold.
        $this->assertStringContainsString('#fdd017', Flags::forLanguage('ckb'), 'Central Kurdish carries the Kurdistan flag.');

        $this->assertSame(
            Flags::forCurrency('IQD'),
            Flags::forLanguage('ar'),
            'العربية carries Iraq, because his shops are in Iraq.'
        );

        $this->assertSame(Flags::forCurrency('GBP'), Flags::forLanguage('en'));
        $this->assertSame(Flags::forCurrency('IRR'), Flags::forLanguage('fa'));
    }

    /**
     * ⚠️ **Drawn into the page, not fetched — Soran, 2026-09-19.**
     *
     * These were `<img src>` and it cost three rounds to get one file to one
     * folder: a shop serves from its own public folder, `shop:provision`
     * missed them, `shop:update` missed them twice over, and when the files
     * finally were in place the page still did not show them. There is no file
     * to fetch now, so none of that can happen again.
     */
    public function test_a_flag_is_drawn_into_the_page_rather_than_fetched(): void
    {
        foreach (['en', 'ckb', 'ar', 'fa'] as $language) {
            $svg = Flags::forLanguage($language);

            $this->assertNotNull($svg, $language.' has no flag.');
            $this->assertStringStartsWith('<svg', $svg, 'A flag should be markup, not a URL.');
            $this->assertStringContainsString('class="app-flag"', $svg);
        }

        // And the page carries them, with nothing to go and get.
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<svg class="app-flag"', $html);
        $this->assertStringNotContainsString('flags/krd.svg', $html, 'Nothing should be fetching a flag by URL.');
    }

    /** The menu carries both halves, with their flags. */
    public function test_the_topbar_offers_language_and_currency_together(): void
    {
        $this->dollars();

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Language and currency"', $html);
        $this->assertStringContainsString('#fdd017', $html, 'The Kurdish flag should be in the menu.');
        $this->assertStringContainsString('#3c3b6e', $html, 'A currency the shop keeps should be in the menu.');
        $this->assertStringContainsString(route('preferences.language'), $html);
        $this->assertStringContainsString(route('preferences.currency'), $html);
    }

    /**
     * ⚠️ One control, not thirty-three. Leaving the old per-screen switcher
     * behind would put two of them on every list page.
     */
    public function test_the_old_per_screen_switcher_is_gone(): void
    {
        $this->dollars();

        foreach (['dashboard', 'products.index', 'sales.index', 'customers.index'] as $screen) {
            $html = $this->actingAs($this->admin)->get(route($screen))->assertOk()->getContent();

            $this->assertSame(
                1,
                substr_count($html, route('preferences.currency')),
                $screen.' draws the currency switcher more than once.'
            );
        }
    }

    /** A switch with one position is furniture — §2b, and it still is. */
    public function test_a_shop_with_one_currency_is_offered_no_lens(): void
    {
        // The seeded shop keeps more than one; this is the shop that does not.
        Currency::where('code', '!=', Money::base()->code)->update(['is_active' => false]);
        Currency::flushCache();

        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('preferences.currency'), $html);

        // The language half is always there.
        $this->assertStringContainsString(route('preferences.language'), $html);
    }

    /**
     * The search is a magnifier on a phone until it is wanted — Soran,
     * 2026-09-19. Asserted on the markup the behaviour hangs off, because the
     * behaviour itself is a browser's business and is checked there.
     */
    public function test_the_phone_search_has_something_to_open_and_close(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('id="app-search-open"', $html);
        $this->assertStringContainsString('id="app-search-close"', $html);
        $this->assertStringContainsString('id="app-topbar-rest"', $html);

        // Hidden below md and never above it: the box keeps `d-md-block`, so a
        // resize cannot leave a half-open bar.
        $this->assertStringContainsString('app-search flex-grow-1 min-w-0 position-relative d-none d-md-block', $html);
    }

    private function dollars(): void
    {
        Currency::updateOrCreate(['code' => 'USD'], [
            'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2,
            'rate' => 1_450 * Money::RATE_SCALE, 'is_active' => true,
        ]);

        Currency::flushCache();
    }
}
