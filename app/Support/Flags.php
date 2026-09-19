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

    /** The markup for a language's flag, or null when there is none to show. */
    public static function forLanguage(string $code): ?string
    {
        return self::svg(self::LANGUAGES[$code] ?? null);
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
        return self::svg(self::CURRENCIES[strtoupper($code)] ?? null);
    }

    /**
     * The flag itself, drawn into the page — Soran, 2026-09-19.
     *
     * ⚠️ **These were `<img src>` and it cost three rounds to get one file to
     * one folder.** A shop serves from its own public folder, so the files had
     * to be copied there; `shop:provision` missed them, then `shop:update`
     * missed them because it copies only when the build changed, then it
     * missed them again because it returns "Already up to date" before doing
     * anything. Each fix was right and each time he came back with the flags
     * still missing — and when the files finally WERE in the folder, the page
     * still did not show them.
     *
     * So there is no file to fetch now. They are a few hundred bytes each,
     * about eight to a page, and drawing them into the markup removes every
     * way this can fail at once: no URL to resolve, no folder to copy to, no
     * MIME type to get right, no 404, nothing to cache and nothing for a
     * content policy to refuse. A picture that cannot fail to arrive beats one
     * that is slightly cheaper.
     *
     * Read from the SHARED codebase, which `git pull` always updates whatever
     * layout the shop uses, rather than from `public_path()`, which is the
     * thing that differed.
     *
     * @var array<string, string|null>
     */
    private static array $cache = [];

    private static function svg(?string $name): ?string
    {
        if ($name === null || ! preg_match('/^[a-z]{2,4}$/', $name)) {
            return null;
        }

        if (array_key_exists($name, self::$cache)) {
            return self::$cache[$name];
        }

        $path = base_path("public/flags/{$name}.svg");

        if (! is_file($path)) {
            return self::$cache[$name] = null;
        }

        $svg = trim((string) file_get_contents($path));

        /*
         * Sized and cropped by the stylesheet's box, the way `object-fit:
         * cover` did for the images: these are drawn at slightly different
         * aspect ratios, and a row of flags at four different heights reads as
         * a mistake rather than a list.
         *
         * `aria-hidden`, because every flag sits beside the name it belongs to
         * and a screen reader saying "image" between them helps nobody.
         */
        $svg = preg_replace(
            '/^<svg /',
            '<svg class="app-flag" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false" ',
            $svg,
            1
        );

        return self::$cache[$name] = $svg;
    }
}
