<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Values that read left-to-right, sitting on the side the page reads from.
 *
 * ⚠️ **`dir="ltr"` on a block element sets its ALIGNMENT as well as its
 * direction.** Put it on a `<td>` and in Kurdish, Arabic or Persian that cell's
 * value hugs the left edge while its own column heading sits on the right.
 *
 * Soran has now pointed at this twice — first at a SKU line on the Products
 * list (2026-09-12, which is where `.app-code` came from), then at the date
 * column on Expenses (2026-09-14). Both times the fix was the same and both
 * times it was applied to the one screen in front of us. This checks the whole
 * of `resources/views` instead, so the third time never happens.
 *
 * A code inside `<span class="app-code">` is the answer: LTR inside, one
 * ordinary inline box outside, aligned by the parent's direction.
 */
class RtlAlignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Printed sheets are the exception, and a deliberate one.
     *
     * A printed report is laid out left-to-right as a whole — it is a document,
     * not a screen, and its columns want the alignment too. `.app-code`'s own
     * comment says so.
     */
    private const PRINTED = 'print/';

    public function test_no_screen_puts_dir_ltr_on_a_table_cell(): void
    {
        $offenders = [];

        foreach ($this->views() as $path => $source) {
            if (str_contains($path, self::PRINTED)) {
                continue;
            }

            if (preg_match_all('/<t[dh]\s[^>]*dir="ltr"/', $source, $m)) {
                foreach ($m[0] as $hit) {
                    $offenders[] = "{$path}: {$hit}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These cells hug the left edge in Kurdish, Arabic and Persian while',
            'their own column heading sits on the right.',
            'Wrap the value instead: <td><span class="app-code">…</span></td>',
            ...$offenders,
        ]));
    }

    /**
     * The lens switcher stands beside full-size buttons, so it is full size.
     *
     * Soran, 2026-09-14, pointing at the actions bar: a 31px switch next to a
     * 38px "New expense" reads as a mistake, and it was one.
     *
     * Checked on the RENDERED markup, not the source. The first version of this
     * read the file and failed on its own comment, which says the words
     * "btn-group-sm" while explaining why they are not there.
     */
    public function test_the_currency_switcher_is_not_a_small_button_group(): void
    {
        // A switch with one position is furniture, so the component hides
        // itself until the shop keeps a second currency.
        $this->seed();

        $html = Blade::render('<x-currency-lens />');

        $this->assertStringContainsString('btn-group', $html, 'the switcher did not render');
        $this->assertStringNotContainsString('btn-group-sm', $html);
    }

    /** @return array<string, string> */
    private function views(): array
    {
        $out = [];

        foreach ($this->files(resource_path('views')) as $file) {
            $out[str_replace(resource_path('views').'/', '', $file)] = file_get_contents($file);
        }

        return $out;
    }

    /** @return list<string> */
    private function files(string $directory): array
    {
        $found = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $found = [...$found, ...$this->files($path)];
            } elseif (str_ends_with($entry, '.blade.php')) {
                $found[] = $path;
            }
        }

        return $found;
    }
}
