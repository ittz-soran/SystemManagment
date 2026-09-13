<?php

namespace App\Support;

use App\Models\Currency;
use Illuminate\Http\Request;

/**
 * Reading a money field that was typed through a lens — Section 2b.
 *
 * A screen set to USD shows its figures in dollars and expects dollars in its
 * boxes. What gets stored is base-currency integers either way; this is the
 * one place that turns the one into the other.
 *
 * ## ⚠️ The untouched-field rule, which is the whole reason this class exists
 *
 * Rounding does not survive a round trip. 10,000 dinars at 1,320 is $7.5757…,
 * which is written `$7.58`, which converts back to **10,006**. A form that
 * converted every field it was given would rewrite each one a few units on
 * every save — so opening a ten-line record, correcting one line and pressing
 * save would silently move the other nine, each still looking plausible, and
 * the total would quietly stop matching the supplier's paperwork.
 *
 * So each money field posts what it SHOWED alongside what it now holds. If
 * those are the same the field was never touched, and the stored figure is
 * returned exactly as it was. Only a field somebody actually typed into is
 * converted.
 *
 * ⚠️ **Nothing here mutates the request.** Merging the converted value back in
 * before validation looks tidy and breaks the form: Laravel flashes the request
 * on a failed validation, so `old()` would hand the field a base-currency
 * integer to redisplay in a box that is showing dollars. The typed string stays
 * the typed string; validation reads it through `App\Rules\Amount`, and the
 * controller asks here for the figure to store.
 */
final class MoneyInput
{
    /** The companion field a lensed input posts beside itself. */
    public static function shownField(string $field): string
    {
        return $field.'_shown';
    }

    /**
     * What to store for this field, in base-currency minor units.
     *
     * @param  int|null  $original  what the record holds now, for an edit form.
     *                              Null on a create, where nothing can be
     *                              untouched because nothing was pre-filled.
     */
    public static function fromRequest(
        Request $request,
        string $field,
        ?Currency $lens,
        ?int $original = null,
    ): ?int {
        $typed = $request->input($field);

        // No lens, no conversion: the box held base-currency units and the
        // value is whatever was typed, exactly as it has always been.
        if ($lens === null) {
            return Money::parse($typed);
        }

        $shown = $request->input(self::shownField($field));

        if ($original !== null && self::unchanged($typed, $shown)) {
            return $original;
        }

        return Money::parse($typed, $lens);
    }

    /**
     * Is this the figure the field was rendered with?
     *
     * Compared as text with separators stripped, because a person who retypes
     * `1,200` over a field showing `1200` has not changed the amount and must
     * not be charged a rounding for it.
     */
    public static function unchanged(mixed $typed, mixed $shown): bool
    {
        if ($shown === null || $typed === null) {
            return false;
        }

        $tidy = fn (mixed $v): string => trim(str_replace(',', '', (string) $v));

        return $tidy($typed) === $tidy($shown);
    }
}
