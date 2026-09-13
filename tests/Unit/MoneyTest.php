<?php

namespace Tests\Unit;

use App\Models\Currency;
use App\Models\Setting;
use App\Support\AmountInWords;
use App\Support\Money;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * What the stored integer means — Section 2, and the redenomination.
 *
 * Two jobs, and the first matters more than the second:
 *
 * 1. **With the divisor at 1, nothing changes.** Every figure this system has
 *    ever printed still prints identically. That is what makes this safe to
 *    land years before it is needed.
 * 2. With the divisor at 1000, the figures are the ones Soran wrote down on
 *    2026-09-12 — and they are asserted here in his own numbers rather than
 *    paraphrased.
 */
class MoneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The two rows every shop is seeded with — see CurrencySeeder.
        $this->seed(CurrencySeeder::class);
    }

    /**
     * The dinar loses three zeros: the stored integer starts counting fils.
     *
     * Which is now one field on the currency it describes rather than a loose
     * setting beside it — see Section 2b.
     */
    private function afterTheZerosCameOff(): void
    {
        Currency::where('code', 'IQD')->firstOrFail()->update([
            'decimals' => 3,
            'rate' => 1_000 * Money::RATE_SCALE,
        ]);

        Currency::flushCache();
    }

    private function dollars(): Currency
    {
        return Currency::where('code', 'USD')->firstOrFail();
    }

    // ------------------------------------------------------------ as it is now

    public function test_today_the_integer_counts_whole_dinars_and_nothing_moves(): void
    {
        $this->assertSame(1, Money::minorPerMajor());
        $this->assertSame(0, Money::decimals());

        $this->assertSame('250,000', Money::format(250_000));
        $this->assertSame('15,500', Money::format(15_500));
        $this->assertSame('0', Money::format(0));
        $this->assertSame('-1,200', Money::format(-1_200));
        $this->assertSame('1', Money::step());
    }

    public function test_today_a_typed_figure_is_the_figure(): void
    {
        $this->assertSame(250_000, Money::parse('250000'));
        $this->assertSame(250_000, Money::parse('250,000'));
        $this->assertSame(-1_200, Money::parse('-1200'));
        $this->assertNull(Money::parse(''));
        $this->assertNull(Money::parse('abc'));
    }

    // ------------------------------------------------- after the redenomination

    /** ⚠️ Soran's own three lines, as assertions. */
    public function test_the_figures_soran_wrote_down(): void
    {
        $this->afterTheZerosCameOff();

        $this->assertSame('250', Money::format(250_000));
        $this->assertSame('0.25', Money::format(250));
        $this->assertSame('15.5', Money::format(15_500));
    }

    /**
     * ⚠️ Trailing zeros are trimmed. This is the instruction, not a preference:
     * a price list where every figure carries three decimals it does not need
     * is one nobody can scan down.
     */
    public function test_trailing_zeros_are_trimmed_and_a_real_third_place_is_kept(): void
    {
        $this->afterTheZerosCameOff();

        $this->assertSame('15.505', Money::format(15_505));
        $this->assertSame('15.05', Money::format(15_050));
        $this->assertSame('1,250', Money::format(1_250_000));
        $this->assertSame('0.001', Money::format(1));
        $this->assertSame('0', Money::format(0));
        $this->assertSame('-15.5', Money::format(-15_500));
    }

    /**
     * ⚠️ The reason this is done with strings.
     *
     * `(int) (15.5 * 1000)` is 15499 in PHP, and a system that loses one unit
     * per line loses it silently — every total still adds up, each is just a
     * little wrong. Both directions are done in integers for that reason, and
     * these are the values that catch it.
     */
    public function test_no_figure_is_lost_to_binary_floating_point(): void
    {
        $this->afterTheZerosCameOff();

        foreach ([15_500, 250, 1, 8_100, 2_030, 70, 999, 1_000_001] as $stored) {
            $this->assertSame(
                $stored,
                Money::parse(Money::format($stored)),
                "[{$stored}] did not survive being written and read back.",
            );
        }

        // The classic one, directly.
        $this->assertSame(15_500, Money::parse('15.5'));
        $this->assertSame(100, Money::parse('0.1'));
        $this->assertSame(700, Money::parse('0.7'));
        $this->assertSame(2_900, Money::parse('2.9'));
    }

    public function test_a_typed_figure_becomes_the_stored_count(): void
    {
        $this->afterTheZerosCameOff();

        $this->assertSame(250_000, Money::parse('250'));
        $this->assertSame(15_500, Money::parse('15.50'));
        $this->assertSame(250, Money::parse('0.25'));
        $this->assertSame(500, Money::parse('.5'));
        $this->assertSame(-15_500, Money::parse('-15.5'));
        $this->assertSame('0.001', Money::step());
    }

    /** More places than the currency has round up, rather than being cut off. */
    public function test_a_fourth_decimal_rounds(): void
    {
        $this->afterTheZerosCameOff();

        $this->assertSame(15_501, Money::parse('15.5006'));
        $this->assertSame(15_500, Money::parse('15.5004'));
    }

    public function test_the_two_halves_are_available_for_the_written_line(): void
    {
        $this->afterTheZerosCameOff();

        $this->assertSame(['major' => 15, 'minor' => 500], Money::split(15_500));
        $this->assertSame(['major' => 250, 'minor' => 0], Money::split(250_000));
        $this->assertSame(['major' => 0, 'minor' => 250], Money::split(250));

        // The sign belongs to the caller: minus fifteen and a half is not
        // minus fifteen dinars and minus five hundred fils.
        $this->assertSame(['major' => 15, 'minor' => 500], Money::split(-15_500));
    }

    /**
     * With no currencies at all — the shared codebase, a console command run
     * before seeding — a figure still prints rather than throwing.
     */
    public function test_it_falls_back_when_there_is_no_currency_table_to_read(): void
    {
        Currency::query()->delete();
        Currency::flushCache();

        $this->assertSame('IQD', Money::base()->code);
        $this->assertSame('250,000', Money::format(250_000));
        $this->assertSame(250_000, Money::parse('250000'));
    }

    // ------------------------------------------------------- the written line

    /**
     * ⚠️ The one place an amount is written as two numbers.
     *
     * The figure beside it reads 15.5. This reads "fifteen dinars and five
     * hundred fils", because it is a sentence rather than a figure: it is on
     * the invoice so a digit cannot be changed with a pen, and nobody has ever
     * written a payable amount as "fifteen point five".
     */
    public function test_the_written_line_spells_both_halves(): void
    {
        $this->afterTheZerosCameOff();

        $this->assertSame(
            'fifteen dinars and five hundred fils',
            AmountInWords::for(15_500),
        );
    }

    /** Nothing after the point is the sentence this system already wrote. */
    public function test_a_whole_amount_is_written_exactly_as_before(): void
    {
        $this->assertSame('two hundred and fifty thousand dinars', AmountInWords::for(250_000));

        $this->afterTheZerosCameOff();

        $this->assertSame('two hundred and fifty dinars', AmountInWords::for(250_000));
        $this->assertSame('one dinar', AmountInWords::for(1_000));
    }

    /** "Zero dinars and fifty fils" is not something anybody says. */
    public function test_under_one_whole_unit_only_the_small_half_is_written(): void
    {
        $this->afterTheZerosCameOff();

        $this->assertSame('two hundred and fifty fils', AmountInWords::for(250));
        $this->assertSame('one fils', AmountInWords::for(1));
    }

    public function test_a_negative_amount_is_written_once_as_a_minus(): void
    {
        $this->afterTheZerosCameOff();

        $this->assertSame('minus fifteen dinars and five hundred fils', AmountInWords::for(-15_500));
    }

    // ------------------------------------------------------------- the helpers

    public function test_the_helpers_read_the_stored_integer_the_same_way(): void
    {
        $this->afterTheZerosCameOff();

        $this->assertSame('15.5 IQD', money(15_500));
        $this->assertSame('15.5', money(15_500, false));
        $this->assertSame('***** IQD', money_if(false, 15_500));
    }

    /**
     * ⚠️ A chart axis is labelled in thousands of what the reader says, not of
     * what is stored.
     *
     * A shelf worth 90,920,109 fils is 90,920 dinars, so the axis says 90.9 k.
     * Reading the stored count would put "90.9 M" beside a tile reading 90,920
     * — the tile and the chart under it disagreeing by a thousand times.
     */
    public function test_a_chart_axis_counts_what_the_tile_beside_it_counts(): void
    {
        $this->assertSame('90.9 M', money_short(90_920_109));
        $this->assertSame('250 k', money_short(250_000));

        $this->afterTheZerosCameOff();

        $this->assertSame('90.9 k', money_short(90_920_109));
        $this->assertSame('250', money_short(250_000));
        $this->assertSame('15.5', money_short(15_500));
        $this->assertSame('1 M', money_short(999_999_999));
    }

    // ------------------------------------------------------------- the lens

    /**
     * Typing dollars on a page whose shop keeps dinars.
     *
     * §6b's own worked example, which is why these exact numbers: $8.33 at
     * 1,320 is 10,995.6, and the unit price is rounded to a whole dinar so it
     * multiplies cleanly by a quantity.
     */
    public function test_a_price_typed_in_dollars_is_stored_in_dinars(): void
    {
        $usd = $this->dollars();

        $this->assertSame(10_996, Money::parse('8.33', $usd));
        $this->assertSame(660_000, Money::parse('500', $usd));
        $this->assertSame(660_000, Money::parse('500.00', $usd));
        // 29.17 × 1,320 = 38,504.4. A dollar has two decimals, so a price that
        // was a round 38,500 dinars cannot be typed back exactly in dollars —
        // which is the round-trip warning, arriving early.
        $this->assertSame(38_504, Money::parse('29.17', $usd));
    }

    /** And read back through the same lens. */
    public function test_a_stored_figure_is_shown_in_the_chosen_currency(): void
    {
        $usd = $this->dollars();

        $this->assertSame('500', Money::format(660_000, $usd));
        $this->assertSame('8.33', Money::format(10_996, $usd));

        // The base currency through its own lens is just the base currency.
        $this->assertSame('660,000', Money::format(660_000));
        $this->assertSame('660,000', Money::format(660_000, Money::base()));
    }

    /**
     * ⚠️ The dangerous edge, written down as a test rather than a comment.
     *
     * 10,000 dinars is $7.5757…, which is written $7.58, which converts back to
     * 10,006. The round trip does NOT return what it started with, and any
     * screen that converts a field nobody edited will quietly rewrite it. The
     * untouched-field rule exists because of exactly this arithmetic.
     */
    public function test_the_round_trip_does_not_return_what_it_started_with(): void
    {
        $usd = $this->dollars();

        $this->assertSame('7.58', Money::format(10_000, $usd));
        $this->assertSame(10_006, Money::parse('7.58', $usd));

        $this->assertNotSame(
            10_000,
            Money::parse(Money::format(10_000, $usd), $usd),
            'If this ever passes, the untouched-field rule can be dropped — check very carefully first.',
        );
    }

    /** A rate is not a whole number of dinars in every country. */
    public function test_a_fractional_rate_is_carried(): void
    {
        $usd = $this->dollars();
        $usd->update(['rate' => (int) (1_320.125 * Money::RATE_SCALE)]);

        // 500 × 1320.125 = 660,062.5 → rounded half away from zero.
        $this->assertSame(660_063, Money::parse('500', $usd));
    }

    public function test_the_lens_survives_the_redenomination(): void
    {
        $this->afterTheZerosCameOff();
        $usd = $this->dollars();

        // A dollar is still 1,320 of whatever the integer counts — old dinars
        // before, fils after — so the stored figure is unchanged and only the
        // way the base reads it moves.
        $this->assertSame(660_000, Money::parse('500', $usd));
        $this->assertSame('660', Money::format(660_000));
        $this->assertSame('500', Money::format(660_000, $usd));
    }

    /** Each currency's own precision, in the field and in the figure. */
    public function test_the_step_follows_the_currency_in_the_lens(): void
    {
        $this->assertSame('1', Money::step());
        $this->assertSame('0.01', Money::step($this->dollars()));

        $this->afterTheZerosCameOff();

        $this->assertSame('0.001', Money::step());
    }

    /**
     * A figure too large to convert says so rather than wrapping into nonsense.
     *
     * ⚠️ A plain integer, chosen so it reaches the guard being tested. The
     * first version of this passed `PHP_INT_MAX - 1`, which cannot survive a
     * float round trip — PHP 8.5 raised on the cast long before the guard, and
     * 8.3 and 8.4 hid it. CI caught that; a local run on one PHP never could.
     *
     * 1e14 overflows because converting to a two-decimal currency multiplies by
     * 10^2 × RATE_SCALE first, and it is well past AmountInWords::MAX — no
     * shop will ever hold it.
     */
    public function test_an_impossible_figure_is_refused_not_wrapped(): void
    {
        $usd = $this->dollars();

        $this->expectExceptionMessage('too large');

        Money::format(100_000_000_000_000, $usd);
    }

    /** A currency with no rate cannot be a lens, and says so. */
    public function test_a_currency_with_no_rate_is_refused(): void
    {
        $usd = $this->dollars();
        $usd->forceFill(['rate' => 0]);

        $this->expectExceptionMessage('no exchange rate');

        Money::format(660_000, $usd);
    }

    /**
     * ⚠️ The day this ships, every shop's cache still holds what the PREVIOUS
     * release put under this key.
     *
     * Without a shape check, the first page load after the deploy is a 500 on
     * every screen that draws a figure — and the way out is a command nobody
     * can reach, because the panel is one of the screens that is down. So a
     * cached value that is not rows is thrown away and the table is asked.
     */
    public function test_a_cache_left_by_an_older_release_does_not_take_the_shop_down(): void
    {
        // What the first version of Currency::cached() wrote: model objects.
        Cache::forever(Currency::CACHE_KEY, ['IQD' => Currency::where('code', 'IQD')->firstOrFail()]);

        $this->assertSame('IQD', Money::base()->code);
        $this->assertSame('250,000', Money::format(250_000));

        // Anything at all, in fact.
        Cache::forever(Currency::CACHE_KEY, 'nonsense from a release nobody remembers');

        $this->assertSame('250,000', Money::format(250_000));
    }
}
