<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The till bar: the running total and Save, on a phone, always there.
 *
 * **Section 9b, 2026-09-15.** On a laptop the totals panel stands beside the
 * cart and the figure Soran reads out to the customer is never out of sight. On
 * a phone that panel stacks underneath: with four lines scanned, Save sat about
 * fourteen hundred pixels below the scanner, and the total was down there with
 * it. So below `md` a fixed bar carries both.
 *
 * ⚠️ **The bar's Save lives INSIDE the `<form>`, and that is the whole test.**
 *
 * `app.js` gives every save button in the shop its hold-for-two-seconds guard,
 * and it finds them with `form.querySelectorAll` — walking the form's own
 * descendants. A button attached from outside with `form="sale-form"` submits
 * perfectly well and is never walked, so it would be the one Save in the shop
 * that fires on a single tap. At a till, with a phone in one hand, that is a
 * sale saved by a brush of the thumb.
 *
 * Nothing about that is visible. The button looks right, it saves, and only the
 * guard is missing — which is why it is asserted here rather than left to
 * somebody noticing.
 */
class TillBarTest extends TestCase
{
    use RefreshDatabase;

    /** An XPath predicate matching the whole class, not a substring of it. */
    private const WEARS = 'contains(concat(" ", normalize-space(@class), " "), " app-till-bar ")';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /**
     * ⚠️ The route only, and the form id is looked up below.
     *
     * A provider hands every one of its values to every method that uses it,
     * and PHPUnit **warns** when a method takes fewer than it is given. Three of
     * the four here want only the route, so a two-value provider meant three
     * warnings — and `php artisan test` exits non-zero on a warning while still
     * printing "971 passed". Green tests, red CI, and nothing in the summary
     * saying why.
     *
     * @return list<array{0: string}>
     */
    public static function tills(): array
    {
        return [
            'the sale screen' => ['sales.create'],
            'the purchase screen' => ['purchases.create'],
        ];
    }

    /** The form each till's bar has to be inside. */
    private const FORMS = [
        'sales.create' => 'sale-form',
        'purchases.create' => 'purchase-form',
    ];

    #[DataProvider('tills')]
    public function test_the_bar_is_inside_the_form_that_holds_to_save(string $route): void
    {
        $form = self::FORMS[$route];

        $html = $this->actingAs($this->user)->get(route($route))->assertOk()->getContent();

        /*
         * ⚠️ Walked as a tree, not searched as a string.
         *
         * The first version compared the offsets of `id="sale-form"`, the next
         * `</form>` and `app-till-bar` — and both cart screens carry modals
         * with forms of their own, so "the next `</form>`" is not reliably this
         * form's. It agreed with a bar in the right place and would have gone
         * on agreeing with one in the wrong place.
         */
        $page = new DOMDocument;
        $quiet = libxml_use_internal_errors(true);
        $page->loadHTML((string) $html);
        libxml_use_internal_errors($quiet);

        $xpath = new DOMXPath($page);

        // ⚠️ The whole class, not a substring of one: a bare contains() also
        // matches `app-till-bar-label` and `app-till-bar-total` inside it.
        $bar = '//*['.self::WEARS.']';

        $this->assertSame(1, $xpath->query($bar)->length,
            'Expected exactly one till bar on this screen.');

        $inside = $xpath->query($bar.'/ancestor::form[@id="'.$form.'"]');

        $this->assertSame(1, $inside->length,
            'The till bar is not inside '.$form.'. app.js finds the buttons it guards '
            .'with form.querySelectorAll — walking the form\'s own descendants — so a '
            .'Save out here never gets the hold and saves on one tap.');
    }

    #[DataProvider('tills')]
    public function test_the_bar_says_the_same_total_and_save_as_the_panel(string $route): void
    {
        $html = $this->markupOf($route);

        // Two of each: the panel's, and the bar's. The script drives every
        // element wearing the role, so they cannot fall out of step.
        $this->assertSame(2, substr_count($html, 'data-role="running-total"'),
            'The total is written in the panel and in the till bar, and both carry the role.');
        $this->assertSame(2, substr_count($html, 'data-role="save"'),
            'Save is written in the panel and in the till bar, and both carry the role.');
    }

    /**
     * The page without its scripts.
     *
     * The inline script names the same roles in its selectors —
     * `querySelectorAll('[data-role="save"]')` — and counting those would be
     * counting the code that reads the attribute rather than the elements
     * wearing it.
     */
    private function markupOf(string $route): string
    {
        $html = $this->actingAs($this->user)->get(route($route))->assertOk()->getContent();

        return preg_replace('/<script\b.*?<\/script>/s', '', (string) $html) ?? '';
    }

    /**
     * ⚠️ And it is a phone's bar.
     *
     * Without `d-md-none` a fixed bar would sit across the bottom of a laptop,
     * where the panel beside the cart already says all of this.
     */
    #[DataProvider('tills')]
    public function test_the_bar_is_only_drawn_on_a_phone(string $route): void
    {
        $this->actingAs($this->user)->get(route($route))
            ->assertOk()
            ->assertSee('app-till-bar d-md-none', escape: false);
    }

    /** It starts disabled: there is nothing to save until something is scanned. */
    #[DataProvider('tills')]
    public function test_save_starts_disabled(string $route): void
    {
        $html = $this->actingAs($this->user)->get(route($route))->assertOk()->getContent();

        $bar = substr($html, (int) strpos($html, 'app-till-bar'));
        $bar = substr($bar, 0, (int) strpos($bar, '</div>', (int) strpos($bar, '<button')));

        $this->assertStringContainsString('disabled', $bar,
            'An empty cart must not offer a Save that would write an empty document.');
    }
}
