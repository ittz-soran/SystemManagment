<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every class the shell names has to mean something.
 *
 * **Written 2026-09-15, after `min-vw-0`.** The app layout carried that class
 * on the column beside the sidebar for a year. It is not a Bootstrap utility
 * and it was defined nowhere, so the rule it was supposed to state —
 * `min-width: 0`, which lets a flex item shrink below its own content — was
 * never made. Every list in the shop overflowed sideways on a phone because of
 * it: 784px of products on a 390px screen, 763 of sales, 738 of payments, each
 * dragged bodily sideways to read the second half of a row.
 *
 * Nothing failed. Nothing could: a class that matches no rule is silent, and
 * the browser had no opinion about a name nobody had defined. It took somebody
 * opening the shop on their own phone to find it.
 *
 * So: read the literal class names out of the four layout templates, and fail
 * on any that resolve to nothing at all — no rule in the compiled stylesheet,
 * no rule in the template's own `<style>`, and no mention in the scripts, which
 * is how a class with no styling can still be doing a job.
 *
 * ⚠️ **Scoped to `layouts/`, and that is the whole point.** These four files
 * decide the shape of every screen, they are edited rarely, and a mistake in
 * them is invisible until somebody looks at the shop on the wrong device. The
 * hundred other templates are not worth the false positives.
 */
class LayoutClassTest extends TestCase
{
    public function test_the_layouts_name_no_class_that_does_nothing(): void
    {
        $defined = $this->whatIsStyled();
        $scripts = $this->whatTheScriptsName();
        $dead = [];

        foreach ($this->classesUsedInLayouts() as $class => $where) {
            if (in_array($class, $defined, true) || in_array($class, $scripts, true)) {
                continue;
            }

            $dead[] = $class.'  ('.implode(', ', $where).')';
        }

        sort($dead);

        $this->assertSame([], $dead, implode("\n", [
            'These classes are written in the layouts but resolve to nothing —',
            'no CSS rule and no script that looks for them. Either the name is',
            'wrong (min-vw-0 is not a Bootstrap class; min-w-0 is ours) or the',
            'rule it needs was never written.',
            '',
            ...$dead,
            '',
        ]));
    }

    /**
     * The literal class names in `layouts/`, with the files that use each.
     *
     * Literal only: `bi-{{ $item['icon'] }}` and `text-bg-{{ $variant }}` are
     * decided at render time and there is no name here to look up. Stripping
     * the Blade leaves a bare prefix, and a prefix is skipped rather than
     * reported — the icons have their own guard in IconSubsetTest.
     *
     * @return array<string, list<string>>
     */
    private function classesUsedInLayouts(): array
    {
        $used = [];

        foreach (glob(resource_path('views/layouts/*.blade.php')) as $path) {
            $text = (string) file_get_contents($path);

            preg_match_all('/class="([^"]*)"/', $text, $matches);

            foreach ($matches[1] as $attribute) {
                // Blade first, so what is left is only what was typed.
                $attribute = preg_replace('/\{\{.*?\}\}/s', ' ', $attribute) ?? '';
                $attribute = preg_replace('/@\w+\([^)]*\)/', ' ', $attribute) ?? '';

                foreach (preg_split('/\s+/', $attribute) ?: [] as $class) {
                    // A prefix left behind by an interpolation, not a class.
                    if (! preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $class)) {
                        continue;
                    }

                    $used[$class][basename($path)] = true;
                }
            }
        }

        return array_map(fn (array $files) => array_keys($files), $used);
    }

    /**
     * Every class name the shop's stylesheet defines a rule for.
     *
     * Read from the COMPILED stylesheet rather than the Sass, because that is
     * what a browser is handed: it carries Bootstrap's utilities, our own
     * rules, and whatever a build has dropped. The layouts' own inline
     * `<style>` blocks count too — the print sheet styles itself there.
     *
     * @return list<string>
     */
    private function whatIsStyled(): array
    {
        $css = '';

        foreach (glob(public_path('build/assets/*.css')) as $path) {
            $css .= file_get_contents($path);
        }

        foreach (glob(resource_path('views/layouts/*.blade.php')) as $path) {
            if (preg_match_all('/<style[^>]*>(.*?)<\/style>/s', (string) file_get_contents($path), $blocks)) {
                $css .= implode("\n", $blocks[1]);
            }
        }

        $this->assertNotSame('', $css,
            'No compiled stylesheet to check against. Run `npm run build` first.');

        preg_match_all('/\.(-?[A-Za-z_][\w-]*)/', $css, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Names the scripts look for.
     *
     * A class can be perfectly real and carry no styling — `app-connection-word`
     * is a handle app.js toggles, and styling it would be beside the point.
     * Those are not dead; they are just not decoration.
     *
     * @return list<string>
     */
    private function whatTheScriptsName(): array
    {
        $js = '';

        foreach (glob(resource_path('js/*.js')) as $path) {
            $js .= file_get_contents($path);
        }

        preg_match_all('/[\'"`][^\'"`]*?([a-z][a-z0-9]*(?:-[a-z0-9]+)+)[^\'"`]*?[\'"`]/', $js, $matches);

        return array_values(array_unique($matches[1]));
    }
}
