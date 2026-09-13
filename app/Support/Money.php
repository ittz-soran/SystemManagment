<?php

namespace App\Support;

use App\Models\Currency;
use RuntimeException;

/**
 * What the stored integer means, and how a figure is written and read.
 *
 * ⚠️ **Every money column in this system is one integer in ONE currency, and
 * always will be.** Section 5's FIFO engine needs a batch cost that is an exact
 * whole number multiplying cleanly by a quantity, because Section 7 promises a
 * return reverses COGS to the last unit. Section 6b's rule is unchanged: no
 * currency column on money fields, no dual-currency balances, no historical
 * rate lookup.
 *
 * This class does two jobs and nothing else.
 *
 * ## 1. What the stored integer counts
 *
 * The base currency's `decimals`. Today IQD has 0, so the integer counts whole
 * dinars and every method here is arithmetic that changes nothing. The day the
 * central bank cuts three zeros, IQD gets 3 decimals and the same stored
 * integers read as dinars-and-fils — Soran's own figures:
 *
 *     250,000  →  250          250  →  0.25          15,500  →  15.5
 *
 * Not one row is migrated: the new dinar is worth 1,000 old ones AND holds
 * 1,000 fils, so an amount stored as 250,000 is already the right count of
 * fils. See PROJECT_DOC Section 2b, including the one ratio that would need a
 * real migration.
 *
 * ## 2. Typing and reading in another currency — the lens
 *
 * Asked for by Soran, 2026-09-13: *"can type usd or another currency and
 * system automatically convert to base system currency… should show all prices
 * as selected currency"*. A screen set to USD draws its figures in dollars and
 * expects dollars in its boxes; what it saves is base-currency integers, the
 * same ones it would have saved had they been typed in dinars.
 *
 * So a currency is a lens, not a second set of books. Nothing foreign is ever
 * stored, which is exactly why there is no exchange gain or loss to account
 * for: you never owe dollars, you owe what the dinars came to.
 *
 * ⚠️ **Rounding does not survive a round trip, and that is this design's one
 * dangerous edge.** 10,000 dinars shown at 1,320 is $7.5757…, written $7.58,
 * which converts back to 10,006. A screen that converts a field nobody edited
 * rewrites it. Only fields a person actually typed into may be converted — see
 * the untouched-field rule in Section 2b.
 */
final class Money
{
    /**
     * The rate column's scale: thousandths of a base minor unit.
     *
     * Three decimal places is past what any money-changer quotes, and small
     * enough that converting the largest amount this system can hold
     * (AmountInWords::MAX, near 1e12) back into a two-decimal currency stays
     * inside a 64-bit integer.
     */
    public const RATE_SCALE = 1000;

    /**
     * The currency the books are kept in.
     *
     * Falls back to a currency that is not in the table at all when there is no
     * table to read — the shared codebase the panel provisions from has no
     * database, and `money()` is reachable from console commands that run
     * there. A figure printed by a command is better than a fatal error.
     */
    public static function base(): Currency
    {
        $code = (string) setting('currency_base', 'IQD');
        $all = Currency::cached();

        return $all[$code] ?? reset($all) ?: self::assumed($code);
    }

    /** The one this system had before there was a table to put it in. */
    private static function assumed(string $code): Currency
    {
        $per = max(1, (int) setting('currency_minor_per_major', 1));
        $decimals = in_array($per, [1, 10, 100, 1000], true) ? (int) log10($per) : 0;

        return new Currency([
            'code' => $code,
            'name' => $code,
            'decimals' => $decimals,
            'rate' => (10 ** $decimals) * self::RATE_SCALE,
            'is_active' => true,
        ]);
    }

    /** How many stored units make one unit people say out loud. */
    public static function minorPerMajor(): int
    {
        return self::base()->minorPerMajor();
    }

    /** How many places a figure can carry. Zero while the integer counts whole units. */
    public static function decimals(): int
    {
        return self::base()->decimals;
    }

    /**
     * The stored integer, written the way the shop reads it.
     *
     * ⚠️ **Trailing zeros are trimmed, on Soran's instruction.** 250,000 reads
     * `250` and not `250.000`; 15,500 reads `15.5`. A price list where every
     * figure carries three decimals it does not need is one nobody can scan.
     *
     * ⚠️ **And it is one number, never two.** 15.5, never "15 dinars 500 fils".
     * The compound form belongs in `AmountInWords` and nowhere else.
     *
     * Given a currency, the figure is converted into it first — that is the
     * lens. Digits stay English in every language (Section 9b).
     */
    public static function format(int|float|null $stored, ?Currency $in = null): string
    {
        /*
         * ⚠️ An integer is NOT sent through a float on the way in.
         *
         * `(int) round((float) $x)` looks harmless and is not: a large integer
         * loses precision as a double, and from PHP 8.5 a cast back from a
         * float outside integer range raises rather than saturating quietly.
         * CI on 8.5 found it; 8.3 and 8.4 accepted it in silence, which is the
         * worse outcome of the two. Only a float needs rounding.
         */
        $stored = is_int($stored) ? $stored : (int) round((float) ($stored ?? 0));

        if ($in === null || $in->code === self::base()->code) {
            return self::write($stored, self::base()->decimals);
        }

        return self::write(self::fromBase($stored, $in), $in->decimals);
    }

    /** A count of minor units, written with its separators and its point. */
    private static function write(int $minor, int $decimals): string
    {
        if ($decimals === 0) {
            return number_format($minor);
        }

        // Split before formatting rather than dividing into a float: the
        // fractional part of 15,500 / 1000 is not exactly .5 in binary, and a
        // price list is the wrong place to discover that.
        $per = 10 ** $decimals;
        $negative = $minor < 0;
        $size = abs($minor);

        $major = number_format(intdiv($size, $per));
        $small = rtrim(str_pad((string) ($size % $per), $decimals, '0', STR_PAD_LEFT), '0');

        return ($negative ? '-' : '').$major.($small === '' ? '' : '.'.$small);
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
     * `(int) (2.03 * 1000)` is 2029, and a system that loses one unit per line
     * loses it silently — every total still adds up, each is just a little
     * wrong. Both directions are integer arithmetic for that reason.
     *
     * Given a currency, the number is read in that currency and converted; the
     * answer is always base-currency minor units, because that is the only
     * thing this system stores.
     */
    public static function parse(int|float|string|null $typed, ?Currency $from = null): ?int
    {
        $from ??= self::base();
        $minor = self::read($typed, $from->decimals);

        if ($minor === null) {
            return null;
        }

        return $from->code === self::base()->code ? $minor : self::toBase($minor, $from);
    }

    /** A typed string as a count of minor units at the given precision. */
    private static function read(int|float|string|null $typed, int $decimals): ?int
    {
        $text = str_replace(',', '', trim((string) $typed));

        if ($text === '' || ! preg_match('/^(-)?(\d*)(?:\.(\d*))?$/', $text, $found)) {
            return null;
        }

        [, $sign, $whole, $fraction] = $found + [3 => ''];

        if ($whole === '' && $fraction === '') {
            return null;
        }

        $fraction = (string) $fraction;
        $carry = 0;

        // More places than the currency has round up rather than being cut off:
        // half a fils typed into a price is a person meaning the next one up.
        if (strlen($fraction) > $decimals) {
            $carry = (int) ($fraction[$decimals] >= '5');
            $fraction = substr($fraction, 0, $decimals);
        }

        $small = $decimals === 0 ? 0 : (int) str_pad($fraction, $decimals, '0');
        $value = (int) ($whole === '' ? '0' : $whole) * (10 ** $decimals) + $small + $carry;

        return $sign === '-' ? -$value : $value;
    }

    /**
     * Minor units of `$from`, as base-currency minor units.
     *
     *     base = round(minor × rate ÷ (10^decimals × RATE_SCALE))
     *
     * Rounded half away from zero on the amount itself, once. Section 6b's rule
     * still governs what happens to the remainder on a purchase line: round the
     * UNIT price, never the line total, and let the difference fall into the
     * signed `discount_amount`.
     *
     * Done in integers, though honestly: at every magnitude a shop will ever
     * see, a float would give the same answer, and searching for a case where
     * it does not found none. Unlike `read()` above — where `(int) (2.03 ×
     * 1000)` really is 2029 and a test proves it — this is belt-and-braces
     * rather than a fix for a bug anybody has demonstrated. It is kept because
     * it costs nothing, needs no reasoning about doubles to trust, and carries
     * the overflow guard a float would not trip.
     */
    public static function toBase(int $minor, Currency $from): int
    {
        $divisor = (10 ** $from->decimals) * self::RATE_SCALE;

        return self::divideRounding(self::times($minor, $from->rate), $divisor);
    }

    /**
     * Base-currency minor units, as minor units of `$to` — the reading direction.
     *
     * ⚠️ Not exact, and cannot be: see the round-trip warning above.
     */
    public static function fromBase(int $base, Currency $to): int
    {
        if ($to->rate <= 0) {
            throw new RuntimeException("[{$to->code}] has no exchange rate, so nothing can be shown in it.");
        }

        $scaled = self::times($base, (10 ** $to->decimals) * self::RATE_SCALE);

        return self::divideRounding($scaled, $to->rate);
    }

    /**
     * A multiplication that refuses to wrap.
     *
     * A silently overflowed integer is a wrong price that looks like a price.
     * The guard costs one division and turns an impossible figure into an
     * error somebody can read.
     */
    private static function times(int $a, int $b): int
    {
        if ($a !== 0 && $b !== 0 && abs($a) > intdiv(PHP_INT_MAX, abs($b))) {
            throw new RuntimeException('That amount is too large to convert between currencies.');
        }

        return $a * $b;
    }

    /** Integer division, rounded half away from zero — never through a float. */
    private static function divideRounding(int $value, int $divisor): int
    {
        if ($divisor === 0) {
            throw new RuntimeException('A currency rate of zero cannot be used.');
        }

        $negative = ($value < 0) !== ($divisor < 0);
        $value = abs($value);
        $divisor = abs($divisor);

        $result = intdiv($value, $divisor) + (int) (($value % $divisor) * 2 >= $divisor);

        return $negative ? -$result : $result;
    }

    /**
     * The smallest step a number field may take, as an HTML `step`.
     *
     * `1` for a whole-unit currency, `0.01` for dollars, `0.001` for a dinar
     * that has fils — so a browser stops refusing 15.5 as an invalid number.
     */
    public static function step(?Currency $in = null): string
    {
        $decimals = ($in ?? self::base())->decimals;

        return $decimals === 0 ? '1' : '0.'.str_repeat('0', $decimals - 1).'1';
    }
}
