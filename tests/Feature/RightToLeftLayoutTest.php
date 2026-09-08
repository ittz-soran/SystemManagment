<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The things that only go wrong in Kurdish, Arabic and Persian.
 *
 * This app imports Bootstrap's LTR build and flips it with dir="rtl". That
 * works for everything laid out with logical properties and silently fails for
 * everything laid out with physical ones — and it fails invisibly, because the
 * whole suite is green and the screen is simply wrong for three of the four
 * languages the shop is sold in.
 *
 * Every rule asserted here was found by Soran on his own till, not by a test.
 * They are held against the stylesheet and the templates because there is no
 * cheaper way: a browser would have to be driven in each language to see them,
 * and a lost rule leaves no other trace.
 */
class RightToLeftLayoutTest extends TestCase
{
    private function scss(): string
    {
        return file_get_contents(resource_path('scss/app.scss'));
    }

    /**
     * The back arrow is chosen once, not twice.
     *
     * The stylesheet mirrors every directional icon under [dir='rtl']. The back
     * link also picked its own arrow with a `$isRtl ?` ternary, so the two
     * correct fixes cancelled and the arrow pointed the wrong way in all three
     * RTL languages. Either mechanism alone is right; both together are not.
     */
    public function test_the_back_arrow_is_flipped_by_one_mechanism_only(): void
    {
        $blade = file_get_contents(resource_path('views/components/back-link.blade.php'));

        $this->assertStringContainsString('bi bi-arrow-left', $blade);

        $this->assertDoesNotMatchRegularExpression(
            '/bi-arrow-\{\{\s*\$isRtl/',
            $blade,
            'the back link must not choose its own arrow — the stylesheet already mirrors it, and doing both flips it twice',
        );

        $this->assertMatchesRegularExpression(
            "/\[dir='rtl'\][^{]*\.bi-arrow-left,/s",
            $this->scss(),
            'the stylesheet must be the thing that mirrors directional icons',
        );
    }

    /** Rotation is not direction: undo must not come out looking like redo. */
    public function test_the_rotating_icons_are_never_mirrored(): void
    {
        preg_match("/\[dir='rtl'\] \.bi-arrow-left,(.*?)\{\s*transform: scaleX\(-1\);/s", $this->scss(), $m);

        $this->assertNotEmpty($m, 'the mirror list could not be found');

        foreach (['arrow-repeat', 'arrow-counterclockwise', 'arrow-down-up'] as $icon) {
            $this->assertStringNotContainsString($icon, $m[1], "{$icon} turns, it does not point");
        }
    }

    /**
     * The auto margins are logical in RTL.
     *
     * `.ms-auto` compiles to `margin-left: auto` in this build, which pushes a
     * thing to the right of its row — in RTL, back where it started. The
     * topbar's whole control group sat 254px from the left edge with the free
     * space stranded beside it.
     */
    public function test_auto_margins_are_corrected_for_right_to_left(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.ms-auto\s*\{[^}]*margin-right:\s*auto/s',
            $this->scss(),
            'ms-auto must push to the left edge in RTL',
        );

        $this->assertMatchesRegularExpression(
            '/\.me-auto\s*\{[^}]*margin-left:\s*auto/s',
            $this->scss(),
        );
    }

    /** A button group's outer corners are the rounded ones, in both directions. */
    public function test_button_groups_are_rounded_on_the_outside_in_rtl(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.btn-group > \.btn:not\(:last-child\):not\(\.dropdown-toggle\),[^{]*\{[^}]*border-top-right-radius:\s*var\(--bs-btn-border-radius\)/s',
            $this->scss(),
            'the first button in an RTL group is the right-hand one and needs the right corners',
        );
    }

    /**
     * `@json()` prints its own quotes, which is right in a script and wrong in
     * markup.
     *
     * Pasted straight into a template literal's HTML it rendered as
     * `5 "لە کۆگا"` on every line of every cart — quotes and all — in all four
     * languages. Wrapping it in `${…}` makes it a value again.
     */
    public function test_no_translation_is_pasted_into_markup_with_its_json_quotes(): void
    {
        $offenders = [];

        foreach (['sales/create', 'sales/edit', 'purchases/create', 'purchases/edit'] as $view) {
            $path = resource_path("views/{$view}.blade.php");

            if (! is_file($path)) {
                continue;
            }

            foreach (file($path) as $number => $line) {
                // A @json() that is inside markup rather than inside ${…}.
                if (preg_match('/(?<!\$\{)@json\(__\(/', $line) && preg_match('/<\w|<\//', $line)) {
                    $offenders[] = "{$view}.blade.php:".($number + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders, 'these print their own JSON quotes into the page');
    }
}
