<?php

namespace Tests\Feature;

use App\Support\AmountInWords;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * The total written out, under the figure.
 *
 * The oldest anti-fraud device on an invoice: a digit can be changed with a pen
 * and a sentence cannot. Soran asked for it under the running total as well,
 * where it does a second job — it is a check on the till. A cashier who meant
 * to type 6,000 and typed 60,000 will not always notice a zero; they will
 * notice "sixty thousand".
 *
 * The boundaries are where a number-speller goes wrong: the seam at each scale
 * (999 → 1,000), the teens, the round tens, and the counts a language has a
 * special form for. Arabic has a dual — ألفان, not اثنان ألف — which is the one
 * this got wrong first.
 */
class AmountInWordsTest extends TestCase
{
    private const LANGUAGES = ['en', 'ckb', 'ar', 'fa'];

    public function test_the_figure_soran_asked_for(): void
    {
        App::setLocale('ckb');
        $this->assertSame('شەست هەزار دینار', AmountInWords::for(60_000));

        App::setLocale('en');
        $this->assertSame('sixty thousand dinars', AmountInWords::for(60_000));
    }

    /** English reads like English rather than like an algorithm. */
    public function test_english_reads_as_a_person_would_say_it(): void
    {
        App::setLocale('en');

        $this->assertSame('one dinar', AmountInWords::for(1));
        $this->assertSame('twenty-one dinars', AmountInWords::for(21));
        $this->assertSame('nine hundred and ninety-nine dinars', AmountInWords::for(999));
        $this->assertSame('one thousand dinars', AmountInWords::for(1_000));
        $this->assertSame('sixty-five thousand and five hundred dinars', AmountInWords::for(65_500));
        $this->assertSame('two million and five hundred thousand dinars', AmountInWords::for(2_500_000));
    }

    /** Arabic has a dual, and اثنان ألف is not a number anybody writes. */
    public function test_arabic_uses_its_dual_for_two_of_a_scale(): void
    {
        App::setLocale('ar');

        $this->assertSame('ألفان دينار', AmountInWords::for(2_000));
        $this->assertSame('مليونان دينار', AmountInWords::for(2_000_000));

        // And the و attaches to the word after it rather than floating.
        $this->assertStringContainsString('وخمسمائة', AmountInWords::for(65_500));
    }

    /** Every language says every number, and never says nothing. */
    public function test_nothing_between_zero_and_a_billion_comes_out_empty(): void
    {
        $probes = [
            0, 1, 2, 9, 10, 11, 19, 20, 21, 30, 99, 100, 101, 110, 111, 200, 999,
            1_000, 1_001, 1_100, 2_000, 9_999, 10_000, 100_000, 999_999,
            1_000_000, 2_000_000, 1_000_001, 999_999_999, 1_000_000_000,
            AmountInWords::MAX,
        ];

        foreach (self::LANGUAGES as $language) {
            App::setLocale($language);

            foreach ($probes as $n) {
                $words = AmountInWords::for($n);

                $this->assertNotSame('', trim($words), "{$language} said nothing for {$n}");

                // A missing word list would leave the joining word doubled or
                // stranded, which is how this fails quietly rather than loudly.
                $this->assertStringNotContainsString('  ', $words, "{$language} left a hole at {$n}");
                $this->assertDoesNotMatchRegularExpression('/:\w+/', $words, "{$language} left a placeholder at {$n}");
            }
        }
    }

    /** Different numbers must not produce the same sentence. */
    public function test_the_words_tell_the_numbers_apart(): void
    {
        foreach (self::LANGUAGES as $language) {
            App::setLocale($language);

            $seen = [];

            foreach ([1, 11, 21, 100, 110, 1000, 1100, 10000, 11000, 100000, 1000000] as $n) {
                $words = AmountInWords::for($n);

                $this->assertArrayNotHasKey(
                    $words,
                    $seen,
                    "{$language} says the same thing for {$n} and ".($seen[$words] ?? '?'),
                );

                $seen[$words] = $n;
            }
        }
    }

    /** Beyond the last scale word there is nothing honest to say. */
    public function test_it_says_nothing_rather_than_something_wrong_past_its_limit(): void
    {
        App::setLocale('en');

        $this->assertSame('', AmountInWords::for(AmountInWords::MAX + 1));
        $this->assertNotSame('', AmountInWords::for(AmountInWords::MAX));
    }

    /** A refund is a negative total, and it still has to read as one. */
    public function test_a_negative_total_is_said_as_a_negative(): void
    {
        foreach (self::LANGUAGES as $language) {
            App::setLocale($language);

            $words = AmountInWords::for(-5_000);

            $this->assertNotSame('', trim($words), $language);
            $this->assertStringNotContainsString('-', $words, "{$language} left a minus sign rather than saying it");
        }
    }

    /**
     * The browser writes the same sentence as the server.
     *
     * The sale screen's total moves on every keystroke, so it does the joining
     * itself from a vocabulary the server hands over. Two implementations of
     * one rule is exactly how they drift, so this asserts the vocabulary can
     * build what PHP builds.
     */
    public function test_the_browser_is_given_everything_it_needs(): void
    {
        foreach (self::LANGUAGES as $language) {
            App::setLocale($language);

            $vocabulary = AmountInWords::vocabulary();

            foreach (['units', 'teens', 'tens', 'hundreds', 'scales', 'ones', 'twos'] as $key) {
                $this->assertArrayHasKey($key, $vocabulary, $language);
                $this->assertNotEmpty($vocabulary[$key], "{$language}: {$key} is empty");
            }

            $this->assertCount(10, $vocabulary['units'], $language);
            $this->assertCount(10, $vocabulary['teens'], $language);
            $this->assertCount(10, $vocabulary['tens'], $language);
            $this->assertCount(10, $vocabulary['hundreds'], $language);
            $this->assertCount(3, $vocabulary['scales'], $language);

            // The two patterns carry their markers, or the browser splits on
            // nothing and prints them raw.
            $this->assertStringContainsString('{f}', $vocabulary['join'], $language);
            $this->assertStringContainsString('{s}', $vocabulary['join'], $language);
            $this->assertStringContainsString('{t}', $vocabulary['tensUnits'], $language);
            $this->assertStringContainsString('{u}', $vocabulary['tensUnits'], $language);
            $this->assertStringContainsString('__', $vocabulary['currency'], $language);
        }
    }
}
