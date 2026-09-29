<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The words this shop writes, so it never has to write one twice — Soran,
 * 2026-09-27: *"how add words sugetions for ex i type "monit" auto show
 * "Monitor" click or tab to replase it, this is very importnat"*.
 *
 * ⚠️ **EVERY BOX LEARNS FROM ITS OWN COLUMN.** Customers from customers,
 * devices from repair jobs. A supplier's name has no business finishing a
 * product's, and mixing them would make each list worse at its own job.
 *
 * See Section 9 — "Finishing the word as you type".
 */
final class WordList
{
    /**
     * A word must be at least this long to be worth remembering.
     *
     * At one character every box would offer the alphabet.
     */
    private const SHORTEST = 2;

    /**
     * ⚠️ The list is sent to the browser with the page, so it has a ceiling.
     * The commonest two thousand words carry every name a shop actually types;
     * beyond that it is one-off model codes nobody types a second time.
     */
    private const MOST = 2000;

    /**
     * ⚠️ Only pairs the shop has written at least twice are worth keeping, and
     * only this many of them — otherwise a big catalogue grows a long tail of
     * one-off model codes that nobody will ever type a second time.
     */
    private const PAIRS_SEEN = 2;

    private const PAIRS_MOST = 3000;

    /**
     * Where each box's words come from, and the FIRST source is the one the
     * edited row is taken out of.
     *
     * ⚠️ **EACH LIST IS SCOPED THE WAY ITS SCREEN IS, and the suite caught it
     * not being.** `Supplier` holds walk-in sellers as well as companies, and
     * the supplier screen deliberately shows only companies — so reading the
     * whole table handed the names of people who had sold the shop a
     * second-hand phone to a page that exists precisely not to show them. A
     * word list is not exempt from a scope; it inherits the page's.
     *
     * @return array<string, list<array{0: callable(): Builder<covariant Model>, 1: string}>>
     */
    private static function kinds(): array
    {
        return [
            'products' => [
                [fn () => Product::query(), 'name'],
                // The shop's own words for its shelves, one table away and
                // otherwise unused: Cables, Accessories, Second-hand.
                [fn () => Category::query(), 'name'],
            ],
            'customers' => [[fn () => Customer::query(), 'name']],
            'suppliers' => [[fn () => Supplier::companies(), 'name']],
            'devices' => [[fn () => Repair::query(), 'device']],
            'expenses' => [
                [fn () => Expense::query(), 'title'],
                // The shop's own names for what it spends on: Fuel, Rent.
                [fn () => ExpenseCategory::query(), 'name'],
            ],
        ];
    }

    /**
     * What the browser gets: each word and how many rows use it.
     *
     * ⚠️ **Commonest first, then shortest, then alphabetical.** Determinate on
     * purpose — the same three letters must offer the same word today and
     * tomorrow, or the shop learns not to trust the first suggestion.
     *
     * ⚠️ **The shop outranks the starter list, always.** A word the shop has
     * written keeps its own casing and its own count; the starter word for it
     * is dropped rather than offered twice. Everything the shop has written
     * sorts above everything it has not, so a starter word is only ever the
     * last thing offered.
     *
     * @return list<array{w: string, n: int}>
     */
    public static function for(string $kind, ?int $ignore = null): array
    {
        /*
         * ⚠️ **Kept until something changes, and the fingerprint is what makes
         * that safe.** How many rows there are and when the last one was
         * touched: a saved product moves it, so the next screen rebuilds. The
         * edit screens leave the row being edited out of its own words and are
         * not cached — one screen is not worth a second key.
         */
        if ($ignore !== null) {
            return self::build($kind, $ignore);
        }

        return Cache::remember(
            'words.'.$kind.'.'.self::fingerprint($kind),
            now()->addDay(),
            fn () => self::build($kind),
        );
    }

    /** What has been saved into this box's tables since the list was last built. */
    private static function fingerprint(string $kind): string
    {
        $marks = [];

        foreach (self::kinds()[$kind] ?? throw new \InvalidArgumentException("No word list for [{$kind}].") as [$query]) {
            $rows = $query();
            $marks[] = $rows->count().':'.($rows->max('updated_at') ?? '');
        }

        return md5(implode('|', $marks));
    }

    /** @return list<array{w: string, n: int}> */
    private static function build(string $kind, ?int $ignore = null): array
    {
        $out = [];
        $mine = self::spellings($kind, $ignore);

        foreach ($mine as $word) {
            $out[] = ['w' => $word['forms'][0], 'n' => $word['n']];
        }

        foreach (StarterWords::for($kind) as $word) {
            if (! isset($mine[mb_strtolower($word)])) {
                $out[] = ['w' => $word, 'n' => 0];
            }
        }

        $out = self::withoutSlips($out);

        usort($out, fn (array $a, array $b) => [$b['n'], mb_strlen($a['w']), $a['w']]
            <=> [$a['n'], mb_strlen($b['w']), $b['w']]);

        return array_slice($out, 0, self::MOST);
    }

    /**
     * A word used once, one letter from a word the shop uses often, is a slip.
     *
     * Soran's own catalogue has *Wirless*, typed once, beside *Wireless* in two
     * other products. Offering both is how a dictionary built from real typing
     * goes bad: the slip gets completed, saved again, and becomes a word in its
     * own right.
     *
     * ⚠️ **NOTHING STORED CHANGES.** *Wirless* stays exactly as it is on that
     * product, on its invoices and in every report. The only thing decided here
     * is whether the box offers to type it again.
     *
     * ⚠️ **The bar is deliberately high**, because refusing a word somebody
     * meant is the worse mistake: used exactly once, losing to a word used at
     * least twice or to a curated starter word, both five letters or longer and
     * all letters — `PD`, `30W` and `GF244` are codes, and a code is never a
     * misspelling of another code — and one letter apart, not two.
     *
     * @param  list<array{w: string, n: int}>  $words
     * @return list<array{w: string, n: int}>
     */
    private static function withoutSlips(array $words): array
    {
        /*
         * Bucketed by first letter and length before anything is compared. A
         * one-letter slip keeps both, and a shop with three thousand products
         * has far too many words to compare each against every other.
         */
        $trusted = [];

        foreach ($words as $word) {
            if (($word['n'] >= 2 || $word['n'] === 0) && self::spellable($word['w'])) {
                $trusted[mb_strtolower(mb_substr($word['w'], 0, 1)).mb_strlen($word['w'])][] = mb_strtolower($word['w']);
            }
        }

        return array_values(array_filter($words, function (array $word) use ($trusted) {
            if ($word['n'] !== 1 || ! self::spellable($word['w'])) {
                return true;
            }

            $key = mb_strtolower($word['w']);
            $first = mb_strtolower(mb_substr($word['w'], 0, 1));
            $length = mb_strlen($word['w']);

            /*
             * ⚠️ **Three lengths, not one.** *Wirless* is seven letters and
             * *Wireless* is eight — a dropped letter is the commonest slip
             * there is, and bucketing by exact length alone would have missed
             * the very word this was built for.
             */
            foreach ([$length - 1, $length, $length + 1] as $near) {
                foreach ($trusted[$first.$near] ?? [] as $other) {
                    if ($other !== $key && levenshtein($key, $other) === 1) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    /** Long enough and plain enough for one letter out to mean a misspelling. */
    private static function spellable(string $word): bool
    {
        return mb_strlen($word) >= 5
            && preg_match('/^\p{L}+$/u', $word) === 1
            && mb_check_encoding($word, 'ASCII');
    }

    /**
     * Which word tends to follow which, so `Cable ` then `sik` puts **Sikenai**
     * first — the shop has written that pair before.
     *
     * ⚠️ **The shop's own writing only.** The starter list is a bag of words
     * with no sentences behind it, and inventing pairs for it would be
     * guessing at a shop nobody has seen.
     *
     * @return array<string, list<string>> lowercase word => the lowercase words that follow it
     */
    public static function pairs(string $kind, ?int $ignore = null): array
    {
        $seen = [];

        foreach (self::rows($kind, $ignore) as $value) {
            $words = array_values(array_filter(
                explode(' ', trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '')),
                fn (string $word) => mb_strlen($word) >= self::SHORTEST,
            ));

            for ($i = 1; $i < count($words); $i++) {
                $key = mb_strtolower($words[$i - 1]).' '.mb_strtolower($words[$i]);
                $seen[$key] ??= 0;
                $seen[$key]++;
            }
        }

        arsort($seen);

        $out = [];
        $kept = 0;

        foreach ($seen as $key => $count) {
            if ($count < self::PAIRS_SEEN || $kept >= self::PAIRS_MOST) {
                break;
            }

            [$first, $then] = explode(' ', $key, 2);
            $out[$first][] = $then;
            $kept++;
        }

        return $out;
    }

    /**
     * Every casing a word appears in, best first, plus how often the word is
     * used at all under the `n` key.
     *
     * ⚠️ **The commonest casing wins, ties going to the one with more
     * capitals** — `PS4` over `ps4`, which is almost always what a shop means —
     * and then alphabetically, so a catalogue always gives the same answer.
     *
     * @return array<string, array{forms: list<string>, n: int}> keyed by the lowercase word
     */
    public static function spellings(string $kind, ?int $ignore = null): array
    {
        $counts = [];

        foreach (self::rows($kind, $ignore) as $value) {
            foreach (explode(' ', trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '')) as $word) {
                if (mb_strlen($word) < self::SHORTEST) {
                    continue;
                }

                $counts[mb_strtolower($word)][$word] ??= 0;
                $counts[mb_strtolower($word)][$word]++;
            }
        }

        $out = [];

        foreach ($counts as $key => $forms) {
            $total = array_sum($forms);

            uksort($forms, fn (string $a, string $b) => [$forms[$b], self::capitals($b), $a]
                <=> [$forms[$a], self::capitals($a), $b]);

            /*
             * ⚠️ **CAST BACK TO STRING, AND ONLY A BROWSER SHOWED WHY.** PHP
             * turns an all-digit array key into an integer, so `5500` out of
             * *MotherBord B450M-KII+R5 5500* came back as the number 5500,
             * went to the page as `{"w":5500}`, and the first `w.toLowerCase()`
             * threw — killing the suggestions on every box, with the word list
             * sitting there looking perfect.
             */
            $out[(string) $key] = [
                'forms' => array_map(strval(...), array_keys($forms)),
                'n' => $total,
            ];
        }

        return $out;
    }

    /**
     * Every line of writing this box learns from.
     *
     * ⚠️ `$ignore` reaches the FIRST source only — it is the row being edited,
     * and a category is not a product.
     *
     * @return list<string>
     */
    private static function rows(string $kind, ?int $ignore = null): array
    {
        $sources = self::kinds()[$kind] ?? throw new \InvalidArgumentException("No word list for [{$kind}].");

        $out = [];

        foreach ($sources as $at => [$query, $column]) {
            $out = [...$out, ...$query()
                ->when($ignore !== null && $at === 0, fn ($q) => $q->whereKeyNot($ignore))
                ->pluck($column)
                ->filter()
                ->map(fn ($value) => (string) $value)
                ->all()];
        }

        return $out;
    }

    /** How many capital letters a word carries, for breaking a tie. */
    private static function capitals(string $word): int
    {
        return (int) preg_match_all('/\p{Lu}/u', $word);
    }
}
