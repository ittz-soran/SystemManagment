<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Support\AmountInWords;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function afterTheZerosCameOff(): void
    {
        Setting::put('currency_minor_per_major', '1000');
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
     * A settings table is editable by hand, and a shop must not be unable to
     * open its till because somebody typed 3 in a box.
     */
    public function test_a_divisor_that_is_not_a_power_of_ten_is_ignored(): void
    {
        Setting::put('currency_minor_per_major', '3');

        $this->assertSame(1, Money::minorPerMajor());
        $this->assertSame('250,000', Money::format(250_000));
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
}
