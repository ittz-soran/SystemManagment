<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The built stylesheet asks for its fonts by a path that survives being moved.
 *
 * Shared hosting rarely lets you move the document root, so the shop can end up
 * uploaded as sys/ and reached at https://the-shop.com/sys/public/. Vite's
 * default is to write font URLs from the site root — url(/build/assets/…) —
 * which under that layout points at a folder that does not exist. Nothing
 * announces it: the text falls back to a system face, and every icon becomes an
 * empty box. It happened once, on the shop's real host.
 *
 * vite.config.js sets base './' for builds so the URLs come out relative to the
 * stylesheet that asks for them. This fails if that ever comes undone.
 *
 * public/build is not in git, so where there is no build there is nothing to
 * check — CI skips, and the machine that runs `npm run build` is the one that
 * finds out.
 */
class BuiltAssetPathsTest extends TestCase
{
    public function test_the_built_stylesheet_does_not_ask_for_fonts_from_the_site_root(): void
    {
        $sheets = glob(public_path('build/assets/*.css')) ?: [];

        if ($sheets === []) {
            $this->markTestSkipped('No build to check — run `npm run build`.');
        }

        foreach ($sheets as $sheet) {
            preg_match_all('#url\(/[^)]*\)#', (string) file_get_contents($sheet), $matches);

            $this->assertSame([], $matches[0], sprintf(
                "%s asks for assets from the site root, which breaks when the app is served from a subdirectory.\n".
                "Check that vite.config.js still sets base './' for builds, then run `npm run build` again.",
                basename($sheet)
            ));
        }
    }
}
