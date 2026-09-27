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
    /**
     * Two names that are one product spelled two ways.
     *
     * ⚠️ **Reserved for a MISSPELLING, never a variant.** The first version
     * scored names by characters in common and said this about a Blue earphone
     * against the Black one, a 45W charger against the 30W and an M20 mouse
     * against the M10 — three real products out of seven tried. In a shop where
     * nearly every new product is a variant of one on the shelf, a warning that
     * fires on every save is one nobody reads.
     */
    public const SAME_THING = 'same';

    /** Plainly overlapping — the colour, the capacity, the next wattage up. */
    public const CLOSE = 'close';

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
     *
     * ⚠️ **THE SHOP'S OWN CATALOGUE DECIDES HOW A WORD IS WRITTEN.** The rules
     * further down are only what a brand-new shop has. A fixed list of words to
     * capitalise got his real names wrong — `msi` came out `Msi`, `ps4` came out
     * `Ps4`, `type-c` was left alone — while his products say `MSI`, `PS4` and
     * `Type-C`. It is the same decision as `spellingsItKnows()`, applied to
     * casing instead of spelling, and it means this gets better with every
     * product added.
     *
     * @param  array<string, string>|null  $house  the shop's spellings, or null to read them
     */
    public static function tidy(string $raw, ?array $house = null): string
    {
        $house ??= self::houseStyle();

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
            ->map(function (string $word, int $position) use ($house) {
                if ($word === '') {
                    return $word;
                }

                // The shop has written this word before, so it already knows
                // how. Its answer beats every rule below — that is the whole
                // point — except when the reader has typed one of the shop's
                // own spellings, which is never something to overrule.
                $key = mb_strtolower($word);

                if (isset($house[$key])) {
                    return in_array($word, $house[$key], true) ? $word : $house[$key][0];
                }

                if (preg_match('/[\p{Lu}]|[^\p{L}\p{N}]/u', $word)) {
                    return $word;
                }

                if ($position > 0 && in_array($key, self::JOINING, true)) {
                    return $key;
                }

                return Str::ucfirst($word);
            })
            ->implode(' ');
    }

    /**
     * Every word the shop's products use, spelled the way the shop spells it.
     *
     * ⚠️ **The commonest form wins.** A tie goes to the one carrying more
     * capitals — `PS4` over `ps4`, which is almost always what a shop means —
     * and then alphabetically, so the same catalogue always gives the same
     * answer and a name does not change casing between two saves.
     *
     * @return array<string, list<string>> lowercase word => its spellings, best first
     */
    public static function houseStyle(?int $ignore = null): array
    {
        $counts = [];

        foreach (self::catalogue($ignore)->pluck('name') as $name) {
            foreach (explode(' ', trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '')) as $word) {
                if ($word === '') {
                    continue;
                }

                $counts[mb_strtolower($word)][$word] ??= 0;
                $counts[mb_strtolower($word)][$word]++;
            }
        }

        $house = [];

        foreach ($counts as $key => $forms) {
            uksort($forms, fn (string $a, string $b) => [$forms[$b], self::capitals($b), $a] <=> [$forms[$a], self::capitals($a), $b]);

            $house[$key] = array_keys($forms);
        }

        return $house;
    }

    /** How many capital letters a word carries, for breaking a tie. */
    private static function capitals(string $word): int
    {
        return (int) preg_match_all('/\p{Lu}/u', $word);
    }

    /**
     * Products that look like this name, the likeliest first.
     *
     * @return Collection<int, array{id: int, name: string, sku: ?string, verdict: string, score: float}>
     */
    public static function lookAlikes(string $raw, ?int $ignore = null): Collection
    {
        $needle = self::normalise($raw);

        if (mb_strlen($needle) < 4) {
            return collect();
        }

        return self::catalogue($ignore)
            ->map(function (Product $p) use ($needle) {
                ['verdict' => $verdict, 'score' => $score] = self::compare($needle, self::normalise($p->name));

                return ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'verdict' => $verdict, 'score' => $score];
            })
            ->filter(fn (array $hit) => $hit['verdict'] !== null)
            ->sortBy([
                fn (array $a, array $b) => ($b['verdict'] === self::SAME_THING) <=> ($a['verdict'] === self::SAME_THING),
                fn (array $a, array $b) => $b['score'] <=> $a['score'],
            ])
            ->take(3)
            ->values();
    }

    /**
     * How two normalised names relate — word by word, not letter by letter.
     *
     * ⚠️ **This is the fix for the warning that cried wolf.** Characters in
     * common cannot tell *Blue* from *Black*, and in this shop nearly every new
     * product is a variant of one already on the shelf. Words can: the word
     * that differs is either a number (a variant), a near-miss of the other
     * (a misspelling), or something else entirely.
     *
     * @return array{verdict: string|null, score: float}
     */
    private static function compare(string $a, string $b): array
    {
        if ($a === '' || $b === '') {
            return ['verdict' => null, 'score' => 0.0];
        }

        if ($a === $b) {
            return ['verdict' => self::SAME_THING, 'score' => 1.0];
        }

        $left = explode(' ', $a);
        $right = explode(' ', $b);

        // The words both names use, taken out of both — including a word used
        // twice, which is why this is a list and not a set.
        $shared = 0;

        foreach ($left as $i => $word) {
            $at = array_search($word, $right, true);

            if ($at !== false) {
                $shared++;
                unset($left[$i], $right[$at]);
            }
        }

        $left = array_values($left);
        $right = array_values($right);

        /*
         * Not one word in common, so there is nothing to weigh and no reason to
         * pay for the pairing below. On a real catalogue this is almost every
         * product, and this line is what keeps a keystroke cheap.
         */
        if ($shared === 0) {
            return ['verdict' => null, 'score' => 0.0];
        }

        $misspelt = 0;
        $variant = 0;
        $different = 0;

        // What is left over, paired off by whichever two words are closest.
        foreach ($left as $word) {
            $best = null;

            foreach ($right as $at => $other) {
                $gap = self::distance($word, $other);

                if ($best === null || $gap < $best['gap']) {
                    $best = ['gap' => $gap, 'at' => $at, 'word' => $other];
                }
            }

            if ($best === null) {
                // Nothing left to pair with: one name simply says more.
                $different++;

                continue;
            }

            unset($right[$best['at']]);

            match (self::kinship($word, $best['word'])) {
                'variant' => $variant++,
                'misspelt' => $misspelt++,
                default => $different++,
            };
        }

        // Anything still unpaired on the other side is a difference too.
        $different += count($right);

        $parts = $shared + $misspelt + $variant + $different;
        $score = $parts === 0 ? 0.0 : $shared / $parts;

        /*
         * ⚠️ **Certain means every difference is a misspelling** — that is the
         * one case where two names really are one product. More than two
         * misspelt words is not a typo, it is a different product.
         */
        if ($different === 0 && $variant === 0 && $misspelt <= 2 && $shared > 0) {
            // $misspelt === 0 here means the same words in a different order,
            // which is the same product written twice.
            return ['verdict' => self::SAME_THING, 'score' => $score];
        }

        // Worth a look when the words they share outnumber the words they do
        // not, two to one. Below that they are simply two products that happen
        // to begin with the same brand.
        return [
            'verdict' => $shared >= 2 && ($misspelt + $variant + $different) * 2 <= $shared ? self::CLOSE : null,
            'score' => $score,
        ];
    }

    /**
     * What two words that are not the same word are to each other.
     *
     * ⚠️ **Same letters, different digits is ALWAYS a variant** — `30w`/`45w`,
     * `m10`/`m20`, `128gb`/`64gb`. That one rule is what stops the shop being
     * told its next wattage up is a duplicate.
     */
    private static function kinship(string $word, string $other): string
    {
        $letters = fn (string $w) => preg_replace('/\d+/u', '', $w) ?? $w;

        if ((preg_match('/\d/u', $word) || preg_match('/\d/u', $other)) && $letters($word) === $letters($other)) {
            return 'variant';
        }

        // A misspelling is a near-miss between two words long enough for the
        // near-miss to mean something, and a word carrying a digit is a code
        // rather than a word.
        $longest = max(mb_strlen($word), mb_strlen($other));

        if ($longest >= 4 && ! preg_match('/\d/u', $word.$other) && self::distance($word, $other) <= 2) {
            return 'misspelt';
        }

        return 'different';
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
