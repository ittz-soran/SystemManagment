<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Catching a typo before it becomes a second product — Soran, 2026-09-27.
 *
 * He tested four of these on a live page before any of it was built, then
 * chose three: tidy the name on save, warn when it is nearly one he already
 * sells, and offer the spelling his own catalogue already uses. The browser's
 * own spellchecker was the fourth; he held it back after seeing it underline
 * *Sikenai*, *Mcdodo* and *Joyroom* — every brand in the shop — and asked for
 * it a few hours later once the rest worked. It is not in this class: it is one
 * attribute on the product name field and one on `<body>` turning it off
 * everywhere else. See Section 9.
 *
 * ⚠️ **THE DICTIONARY IS HIS CATALOGUE, NOT ENGLISH.** That one decision is
 * what makes this usable in a shop selling Chinese accessories in Iraq: a word
 * is only questioned when it is nearly a word he has already used, so a brand
 * name he has sold once is never questioned again, and *Wirless* still gets
 * caught because *Wireless* is in five products he sells.
 *
 * ⚠️ **Only the tidy changes anything.** The other two advise, on screen, and
 * never block a save — two similar products can be genuinely different, and a
 * shopkeeper standing at a counter must always be able to say "yes, I mean it".
 */
final class ProductName
{
    /** Above this, two names are almost certainly the same product. */
    public const CERTAIN = 0.90;

    /** Above this, worth a second look before saving. */
    public const WORTH_A_LOOK = 0.75;

    /**
     * A word must appear in at least this many products before it is offered
     * as a correction.
     *
     * ⚠️ One product is not a vocabulary. A brand entered once, with its own
     * typo, would otherwise become the spelling every future product is
     * corrected TO — the fault teaching itself.
     */
    private const LEARNED_AFTER = 2;

    /** Words that keep their small letter in the middle of a name. */
    private const JOINING = ['to', 'and', 'or', 'for', 'with', 'in', 'of', 'by', 'on', 'the'];

    /**
     * The name as it should be stored.
     *
     * ⚠️ **Silent, and run on save rather than while typing.** A field that
     * rewrites itself under the cursor is a field nobody can type in.
     */
    public static function tidy(string $raw): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');

        if ($name === '') {
            return '';
        }

        /*
         * The units this shop writes, cased the way its own catalogue cases
         * them. Not a general rule about English: `30w` is written `30W` here
         * because every charger on the shelf says so.
         */
        $units = [
            '/\b(\d+(?:\.\d+)?)\s?w\b/iu' => '${1}W',
            '/\b(\d+(?:\.\d+)?)\s?v\b/iu' => '${1}V',
            '/\b(\d+(?:\.\d+)?)\s?a\b/iu' => '${1}A',
            '/\b(\d+)\s?gb\b/iu' => '${1}GB',
            '/\b(\d+)\s?tb\b/iu' => '${1}TB',
            '/\b(\d+)\s?mah\b/iu' => '${1}mAh',
            '/\b(\d+)\s?hz\b/iu' => '${1}Hz',
        ];

        foreach ($units as $pattern => $replacement) {
            $name = preg_replace($pattern, $replacement, $name) ?? $name;
        }

        // Words the shop always writes in capitals.
        foreach (['LTG', 'USB', 'PD', 'NC', 'LTE', 'SSD', 'HDD', 'RGB', 'LED', 'HDMI'] as $shout) {
            $name = preg_replace('/\b'.$shout.'\b/iu', $shout, $name) ?? $name;
        }

        /*
         * ⚠️ **A word is left exactly as typed if it already carries a capital
         * or any punctuation.** `B450M-KII+R5`, `PD-17-UK` and `i5-10th` are
         * part numbers, and putting a capital on the front of one corrupts it.
         * The hyphen is what tells them apart from `y2`, which is a plain word
         * with a digit in it and does want its capital.
         *
         * ⚠️ Joining words keep their small letter unless they start the name:
         * `C to LTG`, not `C To LTG`. Every one of them is too short and too
         * common to be a part number.
         */
        return collect(explode(' ', $name))
            ->map(function (string $word, int $position) {
                if ($word === '' || preg_match('/[\p{Lu}]|[^\p{L}\p{N}]/u', $word)) {
                    return $word;
                }

                if ($position > 0 && in_array(mb_strtolower($word), self::JOINING, true)) {
                    return mb_strtolower($word);
                }

                return Str::ucfirst($word);
            })
            ->implode(' ');
    }

    /**
     * Products that look like this name, the likeliest first.
     *
     * @return Collection<int, array{id: int, name: string, sku: ?string, score: float}>
     */
    public static function lookAlikes(string $raw, ?int $ignore = null): Collection
    {
        $needle = self::normalise($raw);

        if (mb_strlen($needle) < 4) {
            return collect();
        }

        return self::catalogue($ignore)
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'score' => self::similarity($needle, self::normalise($p->name)),
            ])
            ->filter(fn (array $hit) => $hit['score'] >= self::WORTH_A_LOOK)
            ->sortByDesc('score')
            ->take(3)
            ->values();
    }

    /**
     * Words in this name that are nearly a word the shop already uses.
     *
     * @return Collection<int, array{typed: string, suggested: string, seen: int}>
     */
    public static function spellingsItKnows(string $raw, ?int $ignore = null): Collection
    {
        $known = self::vocabulary($ignore);
        $out = collect();

        foreach (preg_split('/[\s+\/]+/u', $raw) ?: [] as $word) {
            $bare = preg_replace('/[^\p{L}]/u', '', $word) ?? '';

            // Short words and codes are not worth guessing about.
            if (mb_strlen($bare) < 5 || preg_match('/\d/u', $word)) {
                continue;
            }

            $key = mb_strtolower($bare);

            if ($known->has($key)) {
                continue;
            }

            $best = null;

            foreach ($known as $candidate => $about) {
                if (abs(mb_strlen($candidate) - mb_strlen($key)) > 2) {
                    continue;
                }

                $gap = self::distance($key, $candidate);

                if ($gap < 1 || $gap > 2) {
                    continue;
                }

                if ($best === null || $gap < $best['gap'] || ($gap === $best['gap'] && $about['seen'] > $best['seen'])) {
                    $best = ['gap' => $gap, 'seen' => $about['seen'], 'word' => $about['word']];
                }
            }

            if ($best !== null) {
                $out->push(['typed' => $bare, 'suggested' => $best['word'], 'seen' => $best['seen']]);
            }
        }

        return $out->take(4)->values();
    }

    /**
     * Every product, less the one being edited.
     *
     * ⚠️ **Not cached.** Both helps read the whole catalogue and the form asks
     * for both on one keystroke, so a memo is tempting — and wrong: a static
     * one outlives the request under a worker and a test, and would answer a
     * question about a product that has since been renamed. Three columns of a
     * few thousand rows, on a debounced request, is the cheaper mistake.
     *
     * @return Collection<int, Product>
     */
    private static function catalogue(?int $ignore = null): Collection
    {
        return Product::query()
            ->when($ignore !== null, fn ($q) => $q->whereKeyNot($ignore))
            ->get(['id', 'name', 'sku']);
    }

    /**
     * Every word the shop's own product names use, and how often.
     *
     * @return Collection<string, array{word: string, seen: int}>
     */
    private static function vocabulary(?int $ignore = null): Collection
    {
        $counts = [];

        self::catalogue($ignore)
            ->pluck('name')
            ->each(function (string $name) use (&$counts) {
                foreach (preg_split('/[\s+\/]+/u', $name) ?: [] as $word) {
                    $bare = preg_replace('/[^\p{L}]/u', '', $word) ?? '';

                    if (mb_strlen($bare) < 4) {
                        continue;
                    }

                    $key = mb_strtolower($bare);
                    $counts[$key] ??= ['word' => $bare, 'seen' => 0];
                    $counts[$key]['seen']++;
                }
            });

        return collect($counts)->filter(fn ($about) => $about['seen'] >= self::LEARNED_AFTER);
    }

    /** Lower case, letters and digits only, single-spaced. */
    private static function normalise(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)) ?? '');
    }

    /**
     * How alike two normalised names are, from 0 to 1.
     *
     * ⚠️ **Two measures, and the kinder one wins.** Character distance catches
     * a letter changed or dropped; shared words catch the same product typed in
     * a different order. Neither finds both on its own.
     */
    private static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $byWord = self::sharedWords($a, $b);

        /*
         * ⚠️ `levenshtein()` counts BYTES, so a Kurdish or Arabic name would be
         * scored on its UTF-8 encoding rather than its letters. Where either
         * side is not plain ASCII the shared-word measure carries it alone,
         * which is correct if a little less sensitive.
         */
        if (! mb_check_encoding($a, 'ASCII') || ! mb_check_encoding($b, 'ASCII')) {
            return $byWord;
        }

        $gap = self::distance($a, $b);
        $byChar = 1 - $gap / max(strlen($a), strlen($b));

        // Shared words alone never quite reach "certainly the same": two
        // products can share every word and differ by the number that matters.
        return max($byChar, $byWord * 0.97);
    }

    private static function sharedWords(string $a, string $b): float
    {
        $left = array_unique(explode(' ', $a));
        $right = array_unique(explode(' ', $b));

        $both = count(array_intersect($left, $right));
        $either = count(array_unique([...$left, ...$right]));

        return $either === 0 ? 0.0 : $both / $either;
    }

    /** ASCII-safe edit distance; anything else is compared by its letters. */
    private static function distance(string $a, string $b): int
    {
        if (mb_check_encoding($a, 'ASCII') && mb_check_encoding($b, 'ASCII')) {
            return levenshtein($a, $b);
        }

        // levenshtein() is byte-based, so a multi-byte word is walked by
        // character here instead. Product names are short; this is cheap.
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $previous = range(0, count($y));

        foreach ($x as $i => $left) {
            $current = [$i + 1];

            foreach ($y as $j => $right) {
                $current[$j + 1] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + ($left === $right ? 0 : 1),
                );
            }

            $previous = $current;
        }

        return (int) end($previous);
    }
}
