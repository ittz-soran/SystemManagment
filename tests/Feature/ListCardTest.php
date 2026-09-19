<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A list that reads as a card on a phone has to know what its cells are called.
 *
 * **Section 9b, 2026-09-15.** A table is a grid because the eye compares down a
 * column, and a phone has no column to compare down: at 390px a seven-column
 * list is twice the width of the screen. `.table-cards` turns each row into a
 * card below `sm` and each cell into a labelled line — and the label is the word
 * the header would have said, carried on the cell as `data-label`.
 *
 * ⚠️ It has to be on the cell. CSS cannot reach across a table to find the
 * matching `th`, and doing it in JavaScript would leave the first paint
 * unlabelled — a card of bare figures with nothing saying which is the total and
 * which is still owed.
 *
 * Which makes this the thing that rots: add a column to a list a year from now
 * and the desktop table is right, the phone shows a value with no name, and
 * nothing complains. So every cell of every `.table-cards` list is checked to
 * carry either a label or one of the three roles that deliberately has none —
 * the tick, the row's own name, and the actions in the corner.
 */
class ListCardTest extends TestCase
{
    /** The roles a cell may have instead of a label. */
    private const ROLES = ['list-card-check', 'list-card-title', 'list-card-actions'];

    public function test_every_cell_of_every_card_list_says_what_it_is(): void
    {
        $lists = $this->listsThatBecomeCards();

        $this->assertNotSame([], $lists,
            'No list uses .table-cards. Either the class was renamed or the feature was removed.');

        $nameless = [];

        foreach ($lists as $path => $cells) {
            foreach ($cells as $index => $cell) {
                if (str_contains($cell, 'data-label=')) {
                    continue;
                }

                foreach (self::ROLES as $role) {
                    if (str_contains($cell, $role)) {
                        continue 2;
                    }
                }

                $nameless[] = basename(dirname($path)).'/'.basename($path).'  cell '.($index + 1).': '.$cell;
            }
        }

        sort($nameless);

        $this->assertSame([], $nameless, implode("\n", [
            'These cells become a line on a card with nothing to say what they are.',
            'Give each a data-label="{{ __(\'…\') }}" matching its column header, or',
            'one of: '.implode(', ', self::ROLES).'.',
            '',
            ...$nameless,
            '',
        ]));
    }

    /**
     * ⚠️ And a card list has as many cells as it has columns.
     *
     * The labels are matched to the cells by position — the third cell takes the
     * third header's word — so a row with a cell the header does not have, or one
     * fewer, does not merely look odd: every label after it is the name of the
     * wrong figure. A card saying a sale's Due where its Total belongs is worse
     * than no card at all.
     */
    public function test_a_card_list_has_a_cell_for_every_column(): void
    {
        foreach ($this->listsThatBecomeCards() as $path => $cells) {
            $headers = $this->headerCount($path);

            $this->assertCount($headers, $cells, sprintf(
                '%s has %d columns but %d cells in its row. The labels are matched by '
                .'position, so a mismatch names every figure after it wrongly.',
                basename(dirname($path)).'/'.basename($path), $headers, count($cells),
            ));
        }
    }

    /**
     * Every `<td …>` opening tag in the row of each list that uses `.table-cards`.
     *
     * ⚠️ The attribute scan has to step over a Blade expression: the "Due" cell
     * is `class="money {{ $sale->amountDue() > 0 ? … }}"`, and a plain `[^>]*`
     * stops dead at the `>` inside it.
     *
     * @return array<string, list<string>>
     */
    private function listsThatBecomeCards(): array
    {
        $found = [];

        foreach (glob(resource_path('views/*/*.blade.php')) as $path) {
            $text = (string) file_get_contents($path);

            if (! str_contains($text, 'table-cards')) {
                continue;
            }

            if (! preg_match('/<tbody>(.*?)<\/tbody>/s', $text, $body)) {
                continue;
            }

            preg_match_all('/<td\b(?:\{\{.*?\}\}|[^>])*>/s', $body[1], $cells);

            $found[$path] = array_map(
                fn (string $cell) => preg_replace('/\s+/', ' ', $cell) ?? $cell,
                $cells[0],
            );
        }

        return $found;
    }

    private function headerCount(string $path): int
    {
        $text = (string) file_get_contents($path);

        preg_match('/<thead>(.*?)<\/thead>/s', $text, $head);
        preg_match_all('/<th\b/', $head[1] ?? '', $headers);

        return count($headers[0]);
    }
}
