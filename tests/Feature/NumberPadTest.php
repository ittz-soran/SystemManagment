<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 9b: the till is used on a counter, and a price is the number most
 * often typed by hand. Tapping a price or a quantity opens a keypad rather than
 * asking a finger to hit a small number input.
 *
 * The behaviour itself is JavaScript and was checked in a real browser: tapping
 * digits, typing them, Enter to apply, Escape to cancel, and the line total and
 * below-cost warning following along. What is worth pinning down here is the
 * wiring — that the pad is on the page and that every field meant to open it
 * says so.
 */
class NumberPadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);
    }

    public function test_the_keypad_is_on_every_page(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('id="number-pad"', $html);
        $this->assertStringContainsString('data-pad="00"', $html);
        $this->assertStringContainsString('data-pad="back"', $html);
        $this->assertStringContainsString('id="number-pad-ok"', $html);
    }

    /** It is a calculator, not only a keypad: 15000 − 500 is a real sum here. */
    public function test_it_has_the_four_operators_and_equals(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();

        foreach (['+', '-', '*', '/', '='] as $key) {
            $this->assertStringContainsString('data-pad="'.$key.'"', $html);
        }

        // The half-finished sum is shown, so "15000 −" is never a mystery.
        $this->assertStringContainsString('id="number-pad-expression"', $html);
    }

    /**
     * A keypad is not text. Without this the grid mirrors in Sorani, Arabic and
     * Persian and the keys come out 9 8 7 with C on the wrong side.
     */
    public function test_the_grid_stays_left_to_right_in_an_rtl_page(): void
    {
        $stylesheet = collect(glob(public_path('build/assets/*.css')))
            ->map(fn (string $file) => file_get_contents($file))
            ->implode('');

        $this->assertMatchesRegularExpression(
            '/\.number-pad-grid\{[^}]*direction:ltr/',
            $stylesheet,
        );
    }

    public function test_the_sale_cart_opens_it_for_price_and_quantity(): void
    {
        $html = $this->actingAs($this->admin)->get(route('sales.create'))->assertOk()->getContent();

        // Both are built in JavaScript, so the attribute lives in the template
        // string that builds a row.
        $this->assertStringContainsString('data-role="price"', $html);
        $this->assertStringContainsString('data-role="qty"', $html);
        $this->assertSame(2, substr_count($html, 'data-numpad='), 'price and quantity');
    }

    public function test_the_purchase_cart_opens_it_for_quantity_and_both_price_boxes(): void
    {
        $html = $this->actingAs($this->admin)->get(route('purchases.create'))->assertOk()->getContent();

        // Quantity, the dinar price, and the dollars box — which is the one
        // that needs decimals.
        $this->assertSame(3, substr_count($html, 'data-numpad='));
        $this->assertStringContainsString('data-numpad-decimals="2"', $html);
    }

    /**
     * The bug this was found alongside: render() replaced the whole table on
     * every keystroke, so the focused input was destroyed after the first digit
     * and a price could never be more than one character long.
     */
    public function test_the_carts_no_longer_rebuild_the_table_while_a_field_is_being_typed_in(): void
    {
        foreach (['sales', 'purchases'] as $cart) {
            $source = file_get_contents(resource_path("views/{$cart}/create.blade.php"));

            $handler = $this->inputHandler($source);

            $this->assertStringNotContainsString(
                'render();',
                $handler,
                "The {$cart} cart calls render() from its input handler, which destroys the focused field"
            );

            $this->assertStringContainsString('refreshRow(', $handler);
        }
    }

    /** F2 must not save the document from under a half-typed price. */
    public function test_the_save_shortcut_is_ignored_while_the_keypad_is_open(): void
    {
        foreach (['sales', 'purchases'] as $cart) {
            $source = file_get_contents(resource_path("views/{$cart}/create.blade.php"));

            $this->assertStringContainsString("number-pad')?.classList.contains('show')", $source);
        }
    }

    private function inputHandler(string $source): string
    {
        $start = strpos($source, "cartBody.addEventListener('input'");

        $this->assertNotFalse($start, 'The cart should still have an input handler');

        return substr($source, $start, strpos($source, '});', $start) - $start);
    }
}
