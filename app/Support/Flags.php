<?php

namespace App\Support;

/**
 * Which little flag stands beside a language or a currency — Soran,
 * 2026-09-18.
 *
 * ⚠️ **SVG, not emoji, and that is not a style choice.** Chrome and Edge on
 * WINDOWS do not draw flag emoji at all: they show the two letters instead, so
 * the counter PC would read "GB" where the phone reads a flag. And **Kurdish
 * has no emoji flag in the first place** — there is no country code to build
 * one from. Seven files of a few hundred bytes each look the same everywhere.
 *
 * ⚠️ **A language is not a country**, and these two are Soran's own answers
 * rather than a rule anybody can derive: کوردیی ناوەندی carries the Kurdistan
 * flag, and العربية carries Iraq's, because his shops are in Iraq.
 */
final class Flags
{
    /** The four the shop speaks. */
    private const LANGUAGES = [
        'en' => 'gb',
        'ckb' => 'krd',
        'ar' => 'iq',
        'fa' => 'ir',
    ];

    /**
     * Currency code to flag, written out.
     *
     * ⚠️ **A first attempt took the first two letters of the code as the
     * country, and it drew the WRONG FLAG.** Soran's own shop had its dinar
     * coded `IRQ`, and `IR` is Iran — so "Iraq dinar" sat there under an
     * Iranian flag. The rule is right often enough to look like it works and
     * wrong in exactly the case that matters here.
     *
     * So: named, one line each. A currency nobody has written down gets no
     * flag, which reads fine; a currency under another country's flag does
     * not.
     */
    private const CURRENCIES = [
        'IQD' => 'iq',
        'USD' => 'us',
        'GBP' => 'gb',
        'EUR' => 'eu',
        'IRR' => 'ir',
        'SAR' => 'sa',

        // ⚠️ Not a mistake and not ISO 4217: shops that typed their own code
        // for the dinar before the currencies page could correct one. Soran's
        // was exactly this, and it is the code that found the bug above.
        'IRQ' => 'iq',
    ];

    /** The file for a language, or null when there is none to show. */
    public static function forLanguage(string $code): ?string
    {
        return self::file(self::LANGUAGES[$code] ?? null);
    }

    /**
     * The file for a currency code.
     *
     * ⚠️ Falls back to NOTHING rather than to a wrong flag, and now actually
     * does: there is no guessing left in it. A shop may add a currency nobody
     * drew a flag for, and a code with no picture beside it reads fine — a
     * code beside the wrong country does not.
     */
    public static function forCurrency(string $code): ?string
    {
        return self::file(self::CURRENCIES[strtoupper($code)] ?? null);
    }

    /** Only a flag that is actually on disk. */
    private static function file(?string $name): ?string
    {
        if ($name === null || ! preg_match('/^[a-z]{2,4}$/', $name)) {
            return null;
        }

        return is_file(public_path("flags/{$name}.svg")) ? "flags/{$name}.svg" : null;
    }
}
