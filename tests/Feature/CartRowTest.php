<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The cart row's five parts, and the grid that arranges them on a phone.
 *
 * **Section 9b, 2026-09-15.** The cart columns are sized for a laptop — 7.5rem
 * of quantity, 10rem of price, 9rem of total — which is already wider than a
 * 390px screen before the product has said anything. The name column collapsed
 * to nothing, names broke one word to a line, and the line total sat off the
 * edge. Below `sm` the row is a small grid instead: the name and the row's
 * buttons on the first line, the code and what is on the shelf under it, then
 * how many, at what each and what that comes to, side by side.
 *
 * ⚠️ **This is the one piece of layout in the shop that is assembled in
 * JavaScript.** The row is a template string in `render()`, so a cell that
 * loses its class is not a Blade change anybody reviews — and the grid places
 * its children by name. Drop `cart-cell-price` and the box does not disappear;
 * it lands wherever auto-placement puts it, on phones only, while the laptop
 * stays perfect.
 *
 * So both halves are checked: every part is named in the row the server sends,
 * and every name has a rule in the stylesheet the browser is handed.
 */
class CartRowTest extends TestCase
{
    use RefreshDatabase;

    /** The five parts of a line, each placed by name in the grid. */
    private const PARTS = [
        'cart-cell-product',
        'cart-cell-qty',
        'cart-cell-price',
        'cart-cell-total',
        'cart-cell-actions',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::where('email', 'admin@example.com')->firstOrFail();
    }

    /** @return list<array{0: string}> */
    public static function carts(): array
    {
        return [
            'the sale cart' => ['sales.create'],
            'the purchase cart' => ['purchases.create'],
        ];
    }

    #[DataProvider('carts')]
    public function test_the_row_names_all_five_of_its_parts(string $route): void
    {
        $html = $this->actingAs($this->user)->get(route($route))->assertOk()->getContent();

        foreach (self::PARTS as $part) {
            // The bare name, not `class="…`: the total cell is
            // `class="money fw-semibold cart-cell-total"` and its role is not
            // the first word.
            $this->assertSame(1, substr_count((string) $html, $part), sprintf(
                'The cart row should name %s exactly once. The grid places its children '
                .'by name, so a part that loses its class lands wherever auto-placement '
                .'puts it — on a phone only, while the laptop stays perfect.',
                $part,
            ));
        }
    }

    #[DataProvider('carts')]
    public function test_the_cart_table_asks_for_the_phone_layout(string $route): void
    {
        $this->actingAs($this->user)->get(route($route))
            ->assertOk()
            ->assertSee('table-cart', escape: false);
    }

    /**
     * ⚠️ And the other half: the names mean something.
     *
     * A row can name all five parts perfectly and still fall apart, if the
     * stylesheet that places them was renamed or never built. Read from the
     * COMPILED stylesheet, because that is what a browser is handed — the Sass
     * being right is not the same as the build being current.
     */
    public function test_every_part_is_placed_by_the_stylesheet(): void
    {
        $css = '';

        foreach (glob(public_path('build/assets/*.css')) as $path) {
            $css .= file_get_contents($path);
        }

        $this->assertNotSame('', $css,
            'No compiled stylesheet to check against. Run `npm run build` first.');

        foreach ([...self::PARTS, 'table-cart'] as $name) {
            $this->assertStringContainsString('.'.$name, $css,
                $name.' is written on the cart row but no rule places it. On a phone that '
                .'part goes wherever the grid happens to put it.');
        }
    }
}
