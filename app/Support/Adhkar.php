<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The remembrances — أذكار — that sit beside the bell.
 *
 * **Soran, 2026-09-15:** *"for notifications i want add section after alerts,
 * news, history onl, add islamic Remembrance for ex from morning show Morning
 * Remembrances … or all short duas remembrance"*. He then sent seventeen of
 * them and confirmed the split with a screenshot.
 *
 * ⚠️ **The seeded text is his, character for character.** It was machine-copied
 * from what he sent and reassembled to prove it: seventeen pieces, 619
 * characters in and 619 out, identical. AdhkarTest makes that comparison again
 * on every run, because this is religious text and a dropped tashkeel mark is
 * not a typo — it is a different word. Nothing here may be "tidied".
 *
 * ⚠️ **A dhikr is a line, not a record** — the same refusal as Units, for the
 * same reasons. Nothing hangs off one, nothing reports on one, and a shop that
 * wants a different list should be able to clear the box and type its own
 * without a foreign key arguing. The tally is keyed by the text itself (see
 * `key()`), so editing a line starts its count fresh, which is right: it is a
 * different dhikr now.
 */
final class Adhkar
{
    /** Said at any hour — where all seventeen of Soran's live. */
    public const ANY = 'any';

    public const MORNING = 'morning';

    public const EVENING = 'evening';

    /** @var list<string> */
    public const WINDOWS = [self::ANY, self::MORNING, self::EVENING];

    /**
     * ⚠️ Soran's seventeen, exactly as he sent them. Do not edit this string.
     *
     * All of them under ANY, because that is what he asked for: all as any
     * time, no repeat counts. Morning and evening start EMPTY on purpose —
     * those are his to fill from the Settings page. Seeding them would mean me
     * choosing religious text for somebody else's shop, which is not a thing to
     * do by inference.
     */
    public const SEEDED = <<<'ARABIC'
لَا إِلَهَ إِلَّا اللهُ مُحَمَّدٌ رَسُولُ اللهِ
سُبْحَانَ اللهِ وَبِحَمْدِهِ
سُبْحَانَ اللهِ الْعَظِيمِ
أَسْتَغْفِرُ اللهَ وَأَتُوبُ إِلَيْهِ
لَا حَوْلَ وَلَا قُوَّةَ إِلَّا بِاللهِ
الْحَمْدُ للهِ حَمْدًا كَثِيرًا
اللَّهُمَّ صَلِّ عَلَى مُحَمَّدٍ
الْحَمْدُ للهِ عَلَى كُلِّ حَالٍ
حَسْبُنَا اللهُ وَنِعْمَ الْوَكِيلُ
سُبْحَانَ اللهِ وَالْحَمْدُ للهِ
اللهُ أَكْبَرُ كَبِيرًا
اللَّهُمَّ إِنَّكَ عَفُوٌّ تُحِبُّ الْعَفْوَ فَاعْفُ عَنِّي
يَا حَيُّ يَا قَيُّومُ بِرَحْمَتِكَ أَسْتَغِيثُ
رَبِّ اغْفِرْ لِي وَلِوَالِدَيَّ
اللَّهُمَّ أَجِرْنِي مِنَ النَّارِ
اللَّهُمَّ صَلِّ وَسَلِّمْ عَلَى نَبِيِّنَا مُحَمَّدٍ
عَلَيْهِ الصَّلَاةُ وَالسَّلَامُ
ARABIC;

    /**
     * How often one may show itself, in minutes. 0 is off.
     *
     * **Soran, 2026-09-16:** *"i want every 1 min or 5 min show on of
     * Remembrances as notification show on screen"* — he was deciding as he
     * wrote, so both are here and so is off.
     *
     * ⚠️ A closed list, not a free number. A box would let somebody type 0.1
     * and get a remembrance every six seconds, which is not devotion, it is a
     * screen nobody can work at.
     *
     * @var list<int>
     */
    public const EVERY = [0, 1, 5, 15, 30];

    /** Before anybody sets their own hours. Overridden in Settings. */
    public const MORNING_WINDOW = '05:00-11:00';

    public const EVENING_WINDOW = '15:00-19:00';

    /** The written form of a window, as the Settings page stores it. */
    public const DEFAULT_WINDOWS = [
        self::MORNING => self::MORNING_WINDOW,
        self::EVENING => self::EVENING_WINDOW,
    ];

    /**
     * The list for one window, in the order the shop wrote it.
     *
     * @return list<string>
     */
    public static function list(string $window): array
    {
        $default = $window === self::ANY ? self::SEEDED : '';

        return self::parse((string) setting('adhkar_'.$window, $default));
    }

    /**
     * Every window's list, for the Settings page.
     *
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        return collect(self::WINDOWS)
            ->mapWithKeys(fn (string $window) => [$window => self::list($window)])
            ->all();
    }

    /**
     * Which window the shop is in, by the shop's own clock.
     *
     * ⚠️ The shop's timezone, not the server's. A cPanel account in Germany
     * serving a shop in Sulaymaniyah would otherwise call it morning at nine in
     * the evening — and this is the one feature in the system where the hour is
     * the entire point.
     */
    public static function now(): string
    {
        $clock = self::shopTime();
        $minutes = (int) $clock->format('H') * 60 + (int) $clock->format('i');

        foreach ([self::MORNING, self::EVENING] as $window) {
            [$from, $to] = self::windowFor($window);

            if ($minutes >= $from && $minutes < $to) {
                return $window;
            }
        }

        return self::ANY;
    }

    /**
     * What to show right now, and which list it came from.
     *
     * ⚠️ Falls back to the any-time list when the window it is in has nothing
     * in it. A shop that has not written its morning adhkar must not get an
     * empty panel at seven in the morning — it gets the seventeen, which is
     * what it had a minute earlier and what it will have a minute later.
     *
     * @return array{window: string, texts: list<string>}
     */
    public static function forNow(): array
    {
        $window = self::now();
        $texts = self::list($window);

        return $texts === []
            ? ['window' => self::ANY, 'texts' => self::list(self::ANY)]
            : ['window' => $window, 'texts' => $texts];
    }

    /**
     * A window's bounds, as minutes past midnight.
     *
     * ⚠️ Anything unreadable falls back to the built-in hours rather than
     * throwing. The shop clock is consulted while drawing the topbar on every
     * page, and a mistyped setting must not be able to take down every screen
     * in the shop — least of all the till.
     *
     * @return array{0: int, 1: int}
     */
    public static function windowFor(string $window): array
    {
        $default = self::DEFAULT_WINDOWS[$window] ?? self::MORNING_WINDOW;
        $written = trim((string) setting('adhkar_'.$window.'_window', $default));

        $bounds = self::readWindow($written);

        // A window that ends before it begins is somebody's typo, and it would
        // simply never open. Treat it as if nothing had been written.
        return $bounds ?? self::readWindow($default) ?? [0, 0];
    }

    /** The shop's wall clock, wherever the server happens to be. */
    public static function shopTime(): Carbon
    {
        return Carbon::now((string) setting('timezone', config('app.timezone')));
    }

    /**
     * A stable handle for one dhikr, for the tally beside it.
     *
     * The text itself rather than a position in the list: a shop that reorders
     * its adhkar, or deletes one from the middle, must not find this morning's
     * counts attached to the wrong words.
     */
    public static function key(string $text): string
    {
        return substr(sha1($text), 0, 12);
    }

    /**
     * Tidied — the shape the Settings page saves.
     *
     * ⚠️ Whitespace is trimmed off the ends of a line and NOTHING ELSE is
     * touched. No normalising, no stripping of marks, no collapsing of inner
     * spaces: this is Qur'anic and prophetic text, and "tidying" it changes
     * what it says.
     *
     * @param  string|array<int, mixed>  $written
     * @return list<string>
     */
    public static function parse(string|array $written): array
    {
        /*
         * ⚠️ The `u` flag is not optional here, and leaving it off DESTROYS the
         * text rather than merely mis-splitting it.
         *
         * Without it PCRE works in bytes, and `\R` matches the byte 0x85 — which
         * is a perfectly ordinary CONTINUATION byte in the middle of an Arabic
         * letter. Soran's seventeen came back as thirty-two pieces and 577
         * characters instead of 619: forty-two characters cut out of the middle
         * of words, silently, in Qur'anic text.
         *
         * Units::parse() has the same line and is safe only because a unit is
         * "kg". This one is not.
         */
        $lines = is_array($written) ? $written : (preg_split('/\R/u', $written) ?: []);
        $lines = array_map(fn ($line) => trim((string) $line), $lines);

        return array_values(array_unique(array_filter($lines, fn ($line) => $line !== '')));
    }

    /** @return array{0: int, 1: int}|null */
    private static function readWindow(string $written): ?array
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $written, $m)) {
            return null;
        }

        $from = min(23, (int) $m[1]) * 60 + min(59, (int) $m[2]);
        $to = min(23, (int) $m[3]) * 60 + min(59, (int) $m[4]);

        return $to > $from ? [$from, $to] : null;
    }
}
