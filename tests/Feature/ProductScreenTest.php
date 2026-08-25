<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The figures above the product list, and the button below the product form.
 *
 * The figures are the shop at a glance: how many products, how many need
 * reordering, and what the shelves cost. The last of those is the one worth
 * being careful about — it is the money that actually left the till for the
 * units still in stock, read off the batches, not quantity multiplied by a
 * suggested price that may have been edited since.
 *
 * The button is a guard. A barcode scanner types a code and then presses Enter;
 * scanning twice used to save the form on the second Enter. There is no submit
 * button on that form any more, so Enter has nothing to press.
 */
class ProductScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->category = Category::firstOrCreate(['name' => 'Cables']);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create([
            'name' => 'USB Cable 2m',
            'sku' => 'SS-'.fake()->unique()->numerify('#####'),
            'category_id' => $this->category->id,
            'unit' => 'pcs',
            'purchase_price' => 1_000,
            'sale_price' => 1_500,
            'quantity' => 0,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    // ---- The figures ----------------------------------------------------

    public function test_the_figures_describe_the_catalogue_not_the_page(): void
    {
        $this->product(['name' => 'Plenty left', 'quantity' => 50, 'reorder_level' => 5]);
        $this->product(['name' => 'Nearly gone', 'quantity' => 1, 'reorder_level' => 5]);

        $this->actingAs($this->admin)->get(route('products.index'))
            ->assertOk()
            ->assertViewHas('totalProducts', 2)
            ->assertViewHas('lowStockCount', 1);
    }

    /** Section 1 rule 2: the batch cost is the money that actually changed hands. */
    public function test_stock_value_comes_from_the_batches_not_the_suggested_price(): void
    {
        $product = $this->product(['purchase_price' => 1_000]);

        app(StockAdjustmentService::class)->recordOpeningStock(
            product: $product, quantity: 10, unitCost: 800, user: $this->admin,
        );

        // The suggestion is edited afterwards. The shelves did not change value.
        $product->update(['purchase_price' => 999_000]);

        $this->actingAs($this->admin)->get(route('products.index'))
            ->assertOk()
            ->assertViewHas('stockValue', 8_000);
    }

    public function test_a_second_hand_item_is_not_counted_among_the_stock(): void
    {
        $this->product(['name' => 'Ordinary cable']);
        $this->product(['name' => 'Xbox Series S', 'kind' => Product::KIND_USED, 'quantity' => 1]);
        $this->product(['name' => 'Gmail setup', 'kind' => Product::KIND_SERVICE]);

        $this->actingAs($this->admin)->get(route('products.index'))
            ->assertViewHas('totalProducts', 1);
    }

    public function test_the_low_stock_figure_matches_the_rows_its_own_filter_shows(): void
    {
        $this->product(['name' => 'Plenty left', 'quantity' => 50, 'reorder_level' => 5]);
        $this->product(['name' => 'Nearly gone', 'quantity' => 1, 'reorder_level' => 5]);
        $this->product(['name' => 'Retired', 'quantity' => 0, 'reorder_level' => 5, 'is_active' => false]);

        $response = $this->actingAs($this->admin)->get(route('products.index'));
        $counted = $response->viewData('lowStockCount');

        $shown = $this->actingAs($this->admin)
            ->get(route('products.index', ['low_stock' => 1]))
            ->viewData('products');

        $this->assertSame($counted, $shown->total());
    }

    // ---- The button -----------------------------------------------------

    /**
     * The Save button is marked for the hold script, and ships as an ordinary
     * submit so the form is still saveable if that script never arrives.
     */
    public function test_the_save_button_is_marked_for_holding_and_works_without_it(): void
    {
        foreach ([route('products.create'), route('products.edit', $this->product())] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            // Only the product form itself — the topbar has its own little
            // forms for language, theme and logging out, and those are fine.
            preg_match('/<form action="[^"]*\/products[^"]*" method="POST".*?<\/form>/s', $html, $form);

            $this->assertNotEmpty($form, 'The product form was not found on '.$url);

            $this->assertStringContainsString('data-guard-submit', $form[0]);
            $this->assertStringContainsString('type="submit"', $form[0]);
        }
    }

    /** Signing in is not saving a record, and holding a button to log in is grim. */
    public function test_the_login_form_is_exempt_from_holding(): void
    {
        $this->assertStringContainsString(
            'data-hold-exempt',
            $this->get(route('login'))->assertOk()->getContent(),
        );
    }

    /** The form itself is untouched by the guard — saving still works. */
    public function test_saving_still_opens_the_first_fifo_layer(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), [
            'name' => 'Anker charger',
            'category_id' => $this->category->id,
            'unit' => 'pcs',
            'purchase_price' => 12_000,
            'sale_price' => 18_000,
            'is_active' => 1,
            'opening_quantity' => 6,
            'opening_unit_cost' => 11_500,
        ])->assertRedirect(route('products.index'));

        $product = Product::where('name', 'Anker charger')->sole();

        $this->assertSame(6, $product->quantity);
        $this->assertSame(11_500, $product->stockBatches()->sole()->unit_cost);
    }
}
