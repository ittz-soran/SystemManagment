<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The add box — PROJECT_DOC Section 9, the sale/purchase screen, "Adding to the
 * cart". Soran, 2026-11-02: *"when i search for an sku don`t add automaticlly
 * to cart some time have SS12 and SS123 … Every product before add to cart show
 * cacullator to confirm price or change then confirm"*, and the box *"can change
 * in settings to add automaticlly to all or Only when picked from the list"*.
 *
 * The box itself is a page script and was driven in Chromium: typing SS12 and
 * pausing adds nothing and lists SS12; a click or a scan opens the box; a sum
 * is worked out; a second scan into the price is refused; an untouched price
 * in dollars keeps its dinars exactly. What PHP holds is the setting, that both
 * screens carry the box in the mode the shop chose, and the one line that made
 * a pause in typing add a product.
 */
class CartAddBoxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** The box's own tag, read on its own — not anywhere on the page. */
    private function box(string $route): string
    {
        $page = $this->actingAs($this->admin)->get(route($route))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<div[^>]*id="cart-add-box"[^>]*>/', $page, $tag), "{$route} has no add box");

        return $tag[0];
    }

    public function test_every_shop_starts_with_the_box_for_every_product(): void
    {
        $this->assertSame('always', setting('cart_add_box'));
        $this->assertStringContainsString('data-mode="always"', $this->box('sales.create'));
        $this->assertStringContainsString('data-mode="always"', $this->box('purchases.create'));
    }

    public function test_both_screens_open_the_box_the_way_the_shop_chose(): void
    {
        foreach (['picked', 'never'] as $mode) {
            Setting::put('cart_add_box', $mode);

            $this->assertStringContainsString('data-mode="'.$mode.'"', $this->box('sales.create'));
            $this->assertStringContainsString('data-mode="'.$mode.'"', $this->box('purchases.create'));
        }
    }

    public function test_the_settings_page_offers_the_three_ways_and_saves_one(): void
    {
        $page = $this->actingAs($this->admin)->get(route('settings.edit'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<select id="cart_add_box"[^>]*>(.*?)<\/select>/s', $page, $select));
        preg_match_all('/<option value="([a-z]+)"( selected)?/', $select[1], $options);
        $this->assertSame(['always', 'picked', 'never'], $options[1]);
        $this->assertSame(' selected', $options[2][0], 'the shop\'s own choice is not the one shown');

        $this->actingAs($this->admin)
            ->put(route('settings.update'), [...$this->settings(), 'cart_add_box' => 'picked'])
            ->assertSessionHasNoErrors();

        $this->assertSame('picked', setting('cart_add_box'));
    }

    public function test_a_way_that_is_not_one_of_the_three_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->from(route('settings.edit'))
            ->put(route('settings.update'), [...$this->settings(), 'cart_add_box' => 'sometimes'])
            ->assertSessionHasErrors('cart_add_box');

        $this->assertSame('always', setting('cart_add_box'));
    }

    /** A save from a form that does not carry the field leaves it as it was. */
    public function test_a_settings_save_without_the_field_keeps_it(): void
    {
        Setting::put('cart_add_box', 'never');

        $this->actingAs($this->admin)
            ->put(route('settings.update'), $this->settings())
            ->assertSessionHasNoErrors();

        $this->assertSame('never', setting('cart_add_box'));
    }

    /**
     * ⚠️ The bug itself: the search runs whenever typing pauses, and an exact
     * code used to go into the cart from there — SS12 while SS123 was still
     * being typed. Only Enter may take an exact code now, which a scanner sends
     * at the end of every scan. Read from the page, because the whole of it is
     * one condition in the page's script.
     */
    public function test_only_enter_takes_an_exact_code_on_either_screen(): void
    {
        foreach (['sales.create', 'purchases.create'] as $route) {
            $page = $this->actingAs($this->admin)->get(route($route))->assertOk()->getContent();

            // preg_match rather than assertStringContainsString: a failure
            // should name the screen, not print the whole of it.
            $this->assertSame(1, preg_match_all('/data\.exact/', $page), "{$route}: the exact answer is read in more than one place");
            $this->assertSame(1, preg_match("/how === 'enter' && data\\.exact && data\\.products\\.length === 1/", $page), "{$route}: an exact code is taken without Enter");
            $this->assertSame(1, preg_match("/runSearch\\(.*?, 'enter'\\)/", $page), "{$route}: Enter does not ask for the exact code");
        }
    }

    /**
     * Its look — Section 9, Soran's picture, 2026-11-02. One calculator, not
     * two: the add box and the keypad a price field opens carry the same keys,
     * four tinted sums each, and a box that says what it is adding.
     */
    public function test_both_keypads_wear_the_same_keys(): void
    {
        foreach (['sales.create', 'purchases.create'] as $route) {
            $page = $this->actingAs($this->admin)->get(route($route))->assertOk()->getContent();

            foreach (['cart-add-box' => 'id="cart-add-keys"', 'number-pad' => 'id="number-pad-dot"'] as $pad => $inside) {
                $this->assertSame(1, preg_match('/<div[^>]*id="'.$pad.'"(.*?)'.preg_quote($inside, '/').'/s', $page, $match), "{$route}: no {$pad}");
                $start = strpos($page, $match[0]);
                $grid = substr($page, strpos($page, 'number-pad-grid', $start), 4000);
                $grid = substr($grid, 0, strpos($grid, '</div>'));

                $this->assertSame(4, substr_count($grid, 'class="btn number-key number-key-op"'), "{$route}: {$pad} has not four tinted sums");
                $this->assertSame(0, preg_match('/btn-outline-/', $grid), "{$route}: {$pad} still has the old keys");
            }

            // The box shows a badge for the product and names it by kind.
            $this->assertSame(1, preg_match('/kind: product\.kind,\s*note:/', $page), "{$route}: the box is not told the product's kind");
        }
    }

    /**
     * ⚠️ Found in the browser on the dev shop, whose brand is yellow: the sums
     * were drawn in the brand itself, yellow on white, and could not be read.
     * They take the brand made readable — the same one the outline buttons use
     * — on either theme.
     */
    public function test_the_tinted_sums_are_readable_on_a_pale_brand(): void
    {
        Setting::put('primary_color', '#ffe500');
        $palette = brand_palette('#ffe500');

        $page = $this->actingAs($this->admin)->get(route('sales.create'))->assertOk()->getContent();

        $this->assertNotSame('#ffe500', $palette['on_light']);
        $this->assertSame(1, preg_match('/:root,[^{]*\{[^}]*--app-primary-ink: '.preg_quote($palette['on_light'], '/').';/', $page), 'the light ink is not the readable one');
        $this->assertSame(1, preg_match('/\[data-bs-theme="dark"\] \{[^}]*--app-primary-ink: '.preg_quote($palette['on_dark'], '/').';/', $page), 'the dark ink is not the readable one');

        $stylesheet = collect(glob(public_path('build/assets/*.css')))
            ->map(fn (string $file) => file_get_contents($file))
            ->implode('');

        $this->assertSame(1, preg_match('/\.number-key-op\{([^}]*)\}/', $stylesheet, $rule), 'no tinted key in the built stylesheet');
        $this->assertStringContainsString('--app-key-ink:var(--app-primary-ink', $rule[1]);
        $this->assertStringContainsString('--bs-btn-color:var(--app-key-ink)', $rule[1]);
    }

    private function settings(): array
    {
        return [
            'shop_name' => 'Test Shop',
            'primary_color' => '#0d6efd',
            'secondary_color' => '#6c757d',
            'font_family' => 'system-ui, sans-serif',
            'sidebar_style' => 'expanded',
            'default_theme' => 'light',
            'timezone' => 'Asia/Baghdad',
            'usd_rate' => 1320,
            'low_stock_threshold' => 5,
            'sku_prefix' => 'SS',
            'date_format' => 'Y-m-d',
            'units' => "pcs\nkg",
            'default_unit' => 'pcs',
            'backup_frequency' => 'daily',
            'backup_time' => '02:15',
            'backup_weekday' => 5,
            'backup_keep_daily' => 14,
            'backup_keep_monthly' => 12,
            'label_size' => array_key_first(config('labels.sizes')),
        ];
    }
}
