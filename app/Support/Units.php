<?php

namespace App\Support;

/**
 * The units a shop measures things in — asked for by Soran, 2026-09-12:
 * *"in products Unit auto typed pcs, i want add some static and setup in
 * settings"*.
 *
 * ⚠️ **A unit is a label, not a record.** It stays the plain string on
 * `products.unit` that Section 4 describes, and this only decides what the
 * dropdown offers. That is a deliberate refusal of the obvious design — a
 * `units` table with a foreign key — for three reasons:
 *
 *   - Nothing hangs off a unit. A category groups products and is reported on;
 *     "kg" has no properties, no children and no history worth keeping.
 *   - Import and export name the unit as text (MasterDataTransfer), and a
 *     spreadsheet from a supplier is not going to know anybody's id.
 *   - A shop that stops selling cable by the metre would have a foreign key
 *     stopping it from tidying "m" off the list, or an orphaned row if it
 *     didn't. Here the list is just what is offered next time.
 *
 * So a unit removed from the list changes nothing about the products already
 * measured in it — and `forSelect()` keeps offering it to those products, so
 * opening one to edit its price cannot silently re-measure it in pieces.
 *
 * ⚠️ **One unit is not the shop's to remove.** Soran, 2026-09-14: *"have pcs by
 * default added for all systems"*. `ALWAYS` is in every shop's list whether the
 * shop typed it or not — see `withAlways()` for what that is protecting against.
 */
final class Units
{
    /** What a shop is given on its first morning, before anybody edits it. */
    public const SEEDED = "pcs\nbox\nset\npair\npack\nm\ncm\nkg\ng\nlitre\nml\nroll";

    /**
     * The one unit every shop has, whatever else it measures things in.
     *
     * Asked for on 2026-09-14 and enforced rather than merely seeded, because
     * seeding only covers the first morning. A list is one careless save away
     * from empty — clear the boxes, press Save — and an empty list leaves the
     * product form with a dropdown that offers nothing, on the one screen a
     * shop cannot work without. Pinning a single unit means that form always
     * has something true to say.
     */
    public const ALWAYS = 'pcs';

    /**
     * The list, in the order the shop wrote it.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return self::withAlways(self::parse((string) setting('units', self::SEEDED)));
    }

    /**
     * The list with `ALWAYS` in it, wherever the shop put it.
     *
     * Prepended when it is missing, and left exactly where it is when it is
     * not: a shop that wants pieces third has said something, and moving it
     * back to the top on every save would be arguing with them.
     *
     * @param  list<string>  $units
     * @return list<string>
     */
    public static function withAlways(array $units): array
    {
        return in_array(self::ALWAYS, $units, true)
            ? $units
            : [self::ALWAYS, ...$units];
    }

    /**
     * The one a new product starts on.
     *
     * Falls back to the first in the list rather than to a hard-coded "pcs": a
     * shop that sells only cable has no use for pieces, and a default nobody
     * chose is worse than the first thing they did choose.
     */
    public static function default(): string
    {
        $chosen = trim((string) setting('default_unit', ''));
        $all = self::all();

        return $chosen !== '' ? $chosen : ($all[0] ?? 'pcs');
    }

    /**
     * What the dropdown offers for a product that is already measured somehow.
     *
     * ⚠️ Its own unit is always in there, even when the shop has since taken it
     * off the list. Without this, opening a product measured in a retired unit
     * and saving an unrelated field would quietly re-measure it as the first
     * thing in the list — a silent data change made by looking at a page.
     *
     * @return list<string>
     */
    public static function forSelect(?string $current): array
    {
        $all = self::all();
        $current = trim((string) $current);

        if ($current !== '' && ! in_array($current, $all, true)) {
            array_unshift($all, $current);
        }

        // No empty-list case to guard: `all()` always carries ALWAYS.
        return $all;
    }

    /**
     * Tidied — the shape the Settings page saves.
     *
     * Takes either the rows the form posts or one string with a line each, so
     * the stored setting (still one string, so nothing about the settings table
     * had to change) and the boxes on the screen go through the same tidying.
     *
     * Blank lines and repeats are dropped rather than refused: an admin who
     * leaves an empty row or types "kg" twice has not made a mistake worth an
     * error message.
     *
     * @param  string|array<int, mixed>  $written
     * @return list<string>
     */
    public static function parse(string|array $written): array
    {
        $lines = is_array($written) ? $written : (preg_split('/\R/', $written) ?: []);
        $lines = array_map(fn ($line) => trim((string) $line), $lines);

        return array_values(array_unique(array_filter($lines, fn ($line) => $line !== '')));
    }
}
