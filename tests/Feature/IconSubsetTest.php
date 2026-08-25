<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every icon the shop can draw is in the subset.
 *
 * Bootstrap Icons ships about two thousand glyphs — a 131 KB font and a rule
 * for every one of them — and this shop draws sixty-odd. So it carries only
 * those, which is 6 KB.
 *
 * The risk that buys is worth guarding. Add an icon to a template and nothing
 * breaks loudly: the page renders, the layout is fine, and where the icon
 * should be there is an empty box. Nobody notices in review, and the shopkeeper
 * sees a button with nothing on it.
 *
 * So this fails instead, and says what to run.
 */
class IconSubsetTest extends TestCase
{
    /** Names decided by a ternary, where no literal exists to find. */
    private const ALWAYS = [
        'arrow-left', 'arrow-right',
        'sun', 'moon-stars', 'circle-half',
    ];

    private const PATTERNS = [
        // <i class="bi bi-trash">
        '/\bbi-([a-z0-9-]+)/',
        // 'icon' => 'box-seam' in a PHP array, icon="box-seam" on a component
        '/[\'"]?icon[\'"]?\s*(?:=>|=|:)\s*[\'"]([a-z0-9-]+)[\'"]/',
    ];

    public function test_every_icon_the_shop_draws_is_in_the_subset(): void
    {
        $subset = file_get_contents(base_path('resources/scss/_icons.scss'));

        $missing = [];

        foreach ($this->used() as $icon) {
            if (! str_contains($subset, ".bi-{$icon}::before")) {
                $missing[] = 'bi-'.$icon;
            }
        }

        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "These icons are used but not in the subset, so they draw as empty boxes:\n  %s\n\n".
            "Run: python3 tools/subset-icons.py",
            implode("\n  ", $missing),
        ));
    }

    /** @return list<string> */
    private function used(): array
    {
        $names = self::ALWAYS;

        foreach (['resources/views', 'resources/js', 'app'] as $folder) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($folder))
            );

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }

                $text = file_get_contents($file->getPathname());

                foreach (self::PATTERNS as $pattern) {
                    preg_match_all($pattern, $text, $found);
                    $names = [...$names, ...$found[1]];
                }
            }
        }

        // "bi" is the base class. A trailing hyphen is the front half of a name
        // an interpolation finished, and ALWAYS carries what those become.
        return array_values(array_unique(array_filter(
            $names,
            fn (string $n) => $n !== '' && $n !== 'bi' && ! str_ends_with($n, '-'),
        )));
    }
}
