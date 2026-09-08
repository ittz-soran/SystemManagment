<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The compiled assets are committed, so something has to prove they are current.
 *
 * These installs land on shared cPanel hosting with no terminal and no Node, so
 * `npm run build` cannot run at the far end: the compiled CSS, JS and fonts have
 * to travel with the code. That makes `git pull` a whole deploy, which is the
 * point — and it introduces the one failure this file exists to prevent.
 *
 * A stale committed build is worse than no build. The shop serves last week's
 * stylesheet against this week's markup and nothing anywhere says so: every
 * other test passes, because every other test reads the source. The visible
 * symptom is the one Soran actually hit — an icon added to a template draws as
 * an empty rectangle, because the glyph is in the source font subset and not in
 * the compiled one.
 *
 * So the check is against the built output rather than the source, and it is
 * pointed at the two things that go stale silently: the icon subset, and the
 * hand-written rules that only exist in the compiled stylesheet.
 */
class AssetBuildTest extends TestCase
{
    private function manifest(): array
    {
        $path = public_path('build/manifest.json');

        $this->assertFileExists($path, 'Run `npm run build` and commit public/build.');

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Whatever the manifest promises has to actually be on disk. */
    public function test_every_file_the_manifest_names_was_committed(): void
    {
        $missing = [];

        foreach ($this->manifest() as $entry) {
            foreach ([...(array) ($entry['file'] ?? []), ...($entry['css'] ?? [])] as $file) {
                if (! file_exists(public_path('build/'.$file))) {
                    $missing[] = $file;
                }
            }
        }

        $this->assertSame([], $missing, 'the manifest names files that are not in public/build');
    }

    /** The two entry points Blade asks for by name. */
    public function test_the_entry_points_blade_asks_for_are_built(): void
    {
        $manifest = $this->manifest();

        foreach (['resources/scss/app.scss', 'resources/js/app.js'] as $entry) {
            $this->assertArrayHasKey($entry, $manifest, "[{$entry}] is not in the built manifest");
        }
    }

    private function builtCss(): string
    {
        $manifest = $this->manifest();
        $file = $manifest['resources/scss/app.scss']['file'] ?? null;

        $this->assertNotNull($file, 'the stylesheet is missing from the manifest');

        return file_get_contents(public_path('build/'.$file));
    }

    /**
     * Every icon in the subset reached the compiled stylesheet.
     *
     * This is the staleness check that matters. `tools/subset-icons.py` reads
     * the templates and rewrites `_icons.scss`; if that ran and `npm run build`
     * did not, the new icon has a rule in the source and none in the file the
     * browser downloads. The shop then draws an empty box on a screen that
     * every test says is correct.
     */
    public function test_the_committed_stylesheet_has_every_icon_in_the_subset(): void
    {
        preg_match_all(
            '/\.bi-([a-z0-9-]+)::before\s*\{\s*content:\s*"(\\\\[0-9a-f]+)"/i',
            file_get_contents(resource_path('scss/_icons.scss')),
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($matches, 'no icons found in _icons.scss — has the subset been generated?');

        $css = $this->builtCss();
        $stale = [];

        foreach ($matches as [, $name, $codepoint]) {
            // One colon or two: the minifier rewrites `::before` to `:before`,
            // which is the same selector to a browser and a different string to
            // str_contains. Found the honest way — this check failed against a
            // build made sixty seconds earlier.
            if (! preg_match('/\.bi-'.preg_quote($name, '/').'::?before/', $css)) {
                $stale[] = $name;
            }
        }

        $this->assertSame(
            [],
            $stale,
            'these icons are in the subset but not in the committed stylesheet — run `npm run build` and commit public/build',
        );
    }

    /**
     * The hand-written rules compiled in.
     *
     * ScreenHelpTest asserts the RTL offcanvas fix exists in the source; this
     * asserts it survived into the file the browser actually reads. A build
     * that predates the rule passes that test and still opens the help panel
     * over the sidebar in Sorani.
     */
    public function test_the_hand_written_rules_reached_the_compiled_stylesheet(): void
    {
        $css = $this->builtCss();

        $this->assertMatchesRegularExpression(
            '/\[dir=["\']?rtl["\']?\][^{]*\.offcanvas-end/',
            $css,
            'the RTL help-panel fix is not in the committed stylesheet',
        );

        $this->assertStringContainsString(
            'guide-body',
            $css,
            'the guide body measure is not in the committed stylesheet',
        );
    }
}
