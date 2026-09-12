<?php

namespace App\Support;

/**
 * What the stored integer means, and how it is written down.
 *
 * ⚠️ **Every money column in this system is one integer and always will be.**
 * Section 2 and Section 5 rest on it: a batch cost has to be an exact whole
 * number that multiplies cleanly by a quantity, because Section 7 promises a
 * return reverses COGS *to the dinar*. Nothing here changes that, and nothing
 * here is a step towards decimal columns or a currency column.
 *
 * What this class adds is one question the system never used to ask: **what
 * does the integer count?**
 *
 * Today it counts dinars, and `minorPerMajor()` is 1, so every method below is
 * arithmetic that changes nothing — which is deliberate, and is what
 * MoneyTest locks down. The class exists for the day the answer changes.
 *
 * ## The redenomination
 *
 * Iraq's central bank has discussed cutting three zeros off the dinar. Soran
 * described what that means for a shop on 2026-09-12, in his own numbers:
 *
 *     250,000 IQD  →  250 IQD
 *         250 IQD  →  250 fils
 *      15,500 IQD  →  15.5 IQD   (15 dinars and 500 fils, always written 15.5)
 *
 * Read those carefully and they say something useful: the new dinar is worth
 * 1,000 old ones AND is divided into 1,000 fils, so **one fils is worth exactly
 * one old dinar**. An amount stored today as 250,000 is already the correct
 * count of new fils. Not one row needs migrating; the integer stops counting
 * dinars and starts counting fils, and only the reading of it changes.
 *
 * That is the whole design. Set `currency_minor_per_major` to 1000 and every
 * screen, chart, invoice and input follows.
 *
 * ⚠️ It holds only while the two ratios match. A redenomination of 1,000:1
 * into a dinar of 100 fils would need every stored amount divided by ten, and
 * that division is lossy for any figure that is not a multiple of ten — which
 * Section 6b's fractional supplier prices can produce. That case needs a real
 * migration with a stated rounding rule, and it is not written until there is a
 * published ratio to write it against.
 */
final class Money
{
    /**
     * How many stored units make one unit people say out loud.
     *
     * A power of ten, because a currency's subunit always is one and because
     * `decimals()` is its logarithm. Anything else is ignored rather than
     * throwing: a settings table is editable by hand, and a shop must not be
     * unable to open its own till because somebody typed 3 in a box.
     */
    public static function minorPerMajor(): int
    {
        $set = (int) setting('currency_minor_per_major', 1);

        return in_array($set, [1, 10, 100, 1000], true) ? $set : 1;
    }

    /** How many places a figure can carry. Zero while the integer counts whole units. */
    public static function decimals(): int
    {
        return (int) log10(self::minorPerMajor());
    }

    /**
     * The stored integer, written the way the shop reads it.
     *
     * ⚠️ **Trailing zeros are trimmed, on Soran's instruction.** 250,000 reads
     * `250` and not `250.000`; 15,500 reads `15.5` and not `15.500`. A price
     * list where every figure carries three decimals it does not need is a
     * price list nobody can scan down.
     *
     * ⚠️ **And it is one number, never two.** 15.5, never "15 dinars 500 fils".
     * The compound form belongs in `AmountInWords` and nowhere else — see the
     * note there for why the invoice's written line is the one exception.
     *
     * Digits stay English in every language (Section 9b), which is what
     * `number_format` gives.
     */
    public static function format(int|float|null $stored): string
    {
        $per = self::minorPerMajor();
        $stored = (int) round((float) $stored);

        if ($per === 1) {
            return number_format($stored);
        }

        // Split before formatting rather than dividing into a float: the
        // fractional part of 15,500 / 1000 is not exactly .5 in binary, and a
        // price list is the wrong place to discover that.
        $negative = $stored < 0;
        $size = abs($stored);

        $major = number_format(intdiv($size, $per));
        $minor = rtrim(str_pad((string) ($size % $per), self::decimals(), '0', STR_PAD_LEFT), '0');

        return ($negative ? '-' : '').$major.($minor === '' ? '' : '.'.$minor);
    }

    /**
     * The two halves of an amount, for the one place that needs them apart.
     *
     * Only `AmountInWords` uses this. Everywhere else an amount is one number.
     *
     * @return array{major: int, minor: int} both non-negative; the sign is the caller's
     */
    public static function split(int $stored): array
    {
        $per = self::minorPerMajor();
        $size = abs($stored);

        return ['major' => intdiv($size, $per), 'minor' => $size % $per];
    }

    /**
     * A typed figure, as the integer to store.
     *
     * ⚠️ **Parsed as a string, never through a float.** In PHP
     * `(int) (0.1 * 1000)` is 99, and a system that loses one unit per line
     * loses it silently — the totals still add up, they are just each a little
     * wrong. So the two halves are read as digits and combined as integers.
     *
     * Accepts what a person actually types: `15.5`, `15,500`, `١٥.٥` is not
     * accepted and does not need to be (Section 9b keeps every number field in
     * English digits).
     */
    public static function parse(int|float|string|null $typed): ?int
    {
        $text = trim((string) $typed);

        if ($text === '') {
            return null;
        }

        // A thousands separator is what a person pastes out of a spreadsheet.
        $text = str_replace(',', '', $text);

        if (! preg_match('/^(-)?(\d*)(?:\.(\d*))?$/', $text, $found)) {
            return null;
        }

        [, $sign, $whole, $fraction] = $found + [3 => ''];

        if ($whole === '' && $fraction === '') {
            return null;
        }

        $per = self::minorPerMajor();
        $places = self::decimals();

        // More places than the currency has are rounded, not truncated: half a
        // fils typed into a price is a person meaning the next one up.
        $fraction = (string) $fraction;
        $carry = 0;

        if (strlen($fraction) > $places) {
            $carry = (int) ($fraction[$places] >= '5');
            $fraction = substr($fraction, 0, $places);
        }

        $minor = $places === 0 ? 0 : (int) str_pad($fraction, $places, '0');
        $value = (int) ($whole === '' ? '0' : $whole) * $per + $minor + $carry;

        return $sign === '-' ? -$value : $value;
    }

    /**
     * The smallest step a number field may take, as an HTML `step`.
     *
     * `1` today. `0.001` once the integer counts fils, so a browser stops
     * refusing 15.5 as an invalid number.
     */
    public static function step(): string
    {
        return self::decimals() === 0 ? '1' : rtrim(rtrim(number_format(1 / self::minorPerMajor(), 3, '.', ''), '0'), '.');
    }
}
