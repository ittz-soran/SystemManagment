<?php

namespace App\Support;

/**
 * The total written out in words, under the figure.
 *
 * The oldest anti-fraud device on an invoice: a digit can be changed with a
 * pen and a sentence cannot. Soran asked for it under the total on the sale
 * screen — شەست هەزار دینار beside 60,000 — and it belongs on the printed
 * invoice for the same reason it has always belonged there.
 *
 * ⚠️ **This is the one place in the system that writes an amount as two
 * numbers.** Everywhere else 15,500 reads `15.5` and never "15 dinars 500
 * fils" — Soran's instruction, and `Money::format` enforces it. The written
 * line is the exception because it is not a figure, it is a sentence: it
 * exists so a digit cannot be altered with a pen, and "fifteen point five
 * dinars" is not how a written amount is ever set down. So it spells both
 * halves — *fifteen dinars and five hundred fils* — beside a figure reading
 * 15.5.
 *
 * While the stored integer counts whole dinars there is no second half, and
 * every sentence this writes is exactly what it wrote before `Money` existed.
 * See `Money` for what changes and when.
 *
 * The word lists are `__()` strings so that `translations:check` counts them,
 * and so the same lists can be handed to the sale screen, which has to write
 * the words again in the browser as the total changes. What is duplicated
 * there is the joining, not the vocabulary.
 *
 * Grammar, honestly: this is invoice Arabic rather than examination Arabic.
 * Numbers in Arabic decline for case, gender and duality, and an invoice
 * writes them the plain way — خمسة وستون ألف دينار — which is what a shop
 * reads out and what its customers expect. The same simplification is why
 * Kurdish and Persian join every part with و and stop there.
 */
final class AmountInWords
{
    /** Above this there is no scale word left, and no shop has the problem. */
    public const MAX = 999_999_999_999;

    public static function for(int $amount): string
    {
        if ($amount < 0) {
            return __('minus :words', ['words' => self::for(-$amount)]);
        }

        if ($amount > self::MAX) {
            return '';
        }

        ['major' => $major, 'minor' => $minor] = Money::split($amount);

        // Nothing after the point: the sentence this system has always written.
        if ($minor === 0) {
            return self::major($major);
        }

        // Under one whole unit, the halves would read "zero dinars and fifty
        // fils", which nobody says. The small half stands on its own.
        if ($major === 0) {
            return self::minor($minor);
        }

        return __(':first and :second', [
            'first' => self::major($major),
            'second' => self::minor($minor),
        ]);
    }

    /** The whole units, as a sentence. */
    private static function major(int $amount): string
    {
        return $amount === 1
            ? __('one dinar')
            : trim(__(':words dinars', ['words' => self::words($amount)]));
    }

    /** The part after the point, as a sentence. Unreachable while there is no such part. */
    private static function minor(int $amount): string
    {
        return $amount === 1
            ? __('one fils')
            : trim(__(':words fils', ['words' => self::words($amount)]));
    }

    /** The number alone, without the currency. */
    public static function words(int $amount): string
    {
        if ($amount === 0) {
            return self::units()[0];
        }

        $parts = [];

        // Largest scale first, so the sentence reads the way it is spoken.
        foreach ([1_000_000_000 => 3, 1_000_000 => 2, 1_000 => 1] as $size => $scale) {
            if ($amount >= $size) {
                $parts[] = self::scaled(intdiv($amount, $size), $scale);
                $amount %= $size;
            }
        }

        if ($amount > 0) {
            $parts[] = self::underThousand($amount);
        }

        return self::join($parts);
    }

    /**
     * A count of thousands, millions or billions.
     *
     * "One thousand" is said as "a thousand" in all four languages — هەزار,
     * ألف, هزار — so the leading one is dropped rather than spoken.
     */
    private static function scaled(int $count, int $scale): string
    {
        // "One thousand" in English, ألف in Arabic, هەزار in Kurdish: whether
        // the leading one is spoken at all is a fact about the language, so the
        // whole phrase is translated rather than assembled from "one" and a
        // scale word.
        if ($count === 1) {
            return self::ones()[$scale];
        }

        // Arabic has a dual: two thousand is ألفان, one word, not اثنان ألف.
        // The other three just say "two thousand", so the whole phrase is
        // translated and the grammar stays in the language that has it.
        if ($count === 2) {
            return self::twos()[$scale];
        }

        return self::underThousand($count).' '.self::scales()[$scale];
    }

    private static function underThousand(int $n): string
    {
        $parts = [];

        if ($n >= 100) {
            $parts[] = self::hundreds()[intdiv($n, 100)];
            $n %= 100;
        }

        if ($n >= 20) {
            // Sixty-five, not "sixty and five": English hyphenates the pair and
            // the other three join it with و, so the pattern is translated.
            $parts[] = $n % 10 === 0
                ? self::tens()[intdiv($n, 10)]
                : __(':tens-:units', [
                    'tens' => self::tens()[intdiv($n, 10)],
                    'units' => self::units()[$n % 10],
                ]);
            $n = 0;
        }

        if ($n >= 10) {
            $parts[] = self::teens()[$n - 10];
            $n = 0;
        }

        if ($n > 0) {
            $parts[] = self::units()[$n];
        }

        return self::join($parts);
    }

    /**
     * Everything the browser needs to write the same sentence.
     *
     * The sale screen's total changes on every keystroke, so it cannot ask the
     * server for the words. It gets the vocabulary instead.
     *
     * @return array<string, mixed>
     */
    public static function vocabulary(): array
    {
        return [
            'units' => self::units(),
            'teens' => self::teens(),
            'tens' => self::tens(),
            'hundreds' => self::hundreds(),
            'scales' => array_values(self::scales()),
            'ones' => array_values(self::ones()),
            'twos' => array_values(self::twos()),
            'join' => __(':first and :second', ['first' => '{f}', 'second' => '{s}']),
            'tensUnits' => __(':tens-:units', ['tens' => '{t}', 'units' => '{u}']),
            'oneDinar' => __('one dinar'),
            'currency' => __(':words dinars', ['words' => '__']),

            // The second half, for the day the stored integer counts fils. The
            // browser writes the same sentence as the server and must not fall
            // back to English on the one line that exists to be unambiguous.
            'oneMinor' => __('one fils'),
            'minor' => __(':words fils', ['words' => '__']),
            'minorPer' => Money::minorPerMajor(),
            'max' => self::MAX,
        ];
    }

    /**
     * Two parts of a number, joined the way the language joins them.
     *
     * A pattern rather than a separator, because the spacing is part of the
     * grammar: Arabic attaches the و to the word after it — وخمسمائة — and
     * English wants spaces on both sides of its "and".
     *
     * @param  list<string>  $parts
     */
    private static function join(array $parts): string
    {
        $parts = array_values(array_filter($parts));

        return array_reduce(
            array_slice($parts, 1),
            fn (string $carry, string $next) => __(':first and :second', ['first' => $carry, 'second' => $next]),
            $parts[0] ?? '',
        );
    }

    /** @return list<string> zero first */
    private static function units(): array
    {
        return [
            __('zero'), __('one'), __('two'), __('three'), __('four'),
            __('five'), __('six'), __('seven'), __('eight'), __('nine'),
        ];
    }

    /** @return list<string> ten first, nineteen last */
    private static function teens(): array
    {
        return [
            __('ten'), __('eleven'), __('twelve'), __('thirteen'), __('fourteen'),
            __('fifteen'), __('sixteen'), __('seventeen'), __('eighteen'), __('nineteen'),
        ];
    }

    /**
     * Indexed by the tens digit, so [6] is sixty. The first two slots are never
     * read — anything under twenty is a teen or a unit.
     *
     * @return list<string>
     */
    private static function tens(): array
    {
        return [
            '', '', __('twenty'), __('thirty'), __('forty'),
            __('fifty'), __('sixty'), __('seventy'), __('eighty'), __('ninety'),
        ];
    }

    /**
     * Indexed by the hundreds digit. Written out rather than built from
     * "three" plus "hundred", because Persian and Kurdish fuse them —
     * سیصد, نهصد — and Arabic writes ثلاثمائة as one word.
     *
     * @return list<string>
     */
    private static function hundreds(): array
    {
        return [
            '', __('one hundred'), __('two hundred'), __('three hundred'), __('four hundred'),
            __('five hundred'), __('six hundred'), __('seven hundred'), __('eight hundred'), __('nine hundred'),
        ];
    }

    /** @return array<int, string> keyed by the power of a thousand */
    private static function scales(): array
    {
        return [
            1 => __('thousand'),
            2 => __('million'),
            3 => __('billion'),
        ];
    }

    /**
     * Exactly one of a scale, as the language actually says it.
     *
     * @return array<int, string> keyed by the power of a thousand
     */
    private static function ones(): array
    {
        return [
            1 => __('one thousand'),
            2 => __('one million'),
            3 => __('one billion'),
        ];
    }

    /**
     * Exactly two, which Arabic has a separate form for.
     *
     * @return array<int, string> keyed by the power of a thousand
     */
    private static function twos(): array
    {
        return [
            1 => __('two thousand'),
            2 => __('two million'),
            3 => __('two billion'),
        ];
    }
}
