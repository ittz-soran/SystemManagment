<?php

namespace Tests\Feature;

use App\Livewire\Products\Form;
use App\Livewire\Products\Index;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The catalogue and its form, which are Livewire components rather than pages.
 *
 * A page could be tested by asking for it and reading the HTML back. These
 * cannot: the whole point of them is what happens BETWEEN requests — typing
 * narrows the table, a bad SKU is refused while the cursor is still in the box.
 * So the component is driven the way a shopkeeper drives it, one interaction at
 * a time, and the assertions are about what the screen then says.
 *
 * The rules being protected are the ones from the doc, unchanged by the rewrite:
 * a product with stock history is deactivated rather than deleted (Section 5),
 * opening stock becomes a real FIFO layer (Section 5), and prices are whole
 * IQD (Section 2).
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

    // ---- The list -------------------------------------------------------

    public function test_typing_narrows_the_table_without_a_new_page(): void
    {
        $this->product(['name' => 'USB Cable 2m']);
        $this->product(['name' => 'HDMI Cable 3m']);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->assertSee('USB Cable 2m')
            ->assertSee('HDMI Cable 3m')
            ->set('search', 'HDMI')
            ->assertSee('HDMI Cable 3m')
            ->assertDontSee('USB Cable 2m');
    }

    public function test_an_exact_barcode_finds_the_one_product(): void
    {
        $this->product(['name' => 'USB Cable 2m', 'barcode' => '6221031492756']);
        $this->product(['name' => 'HDMI Cable 3m']);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('search', '6221031492756')
            ->assertSee('USB Cable 2m')
            ->assertDontSee('HDMI Cable 3m');
    }

    public function test_the_low_stock_tile_filters_the_list_it_counts(): void
    {
        $low = $this->product(['name' => 'Nearly gone', 'quantity' => 1, 'reorder_level' => 5]);
        $this->product(['name' => 'Plenty left', 'quantity' => 50, 'reorder_level' => 5]);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->assertViewHas('lowStockCount', 1)
            ->set('lowStock', true)
            ->assertSee($low->name)
            ->assertDontSee('Plenty left');
    }

    public function test_the_screen_leaves_second_hand_items_and_services_alone(): void
    {
        $this->product(['name' => 'Ordinary cable']);
        $this->product(['name' => 'Xbox Series S', 'kind' => Product::KIND_USED, 'quantity' => 1]);
        $this->product(['name' => 'Gmail account setup', 'kind' => Product::KIND_SERVICE]);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->assertSee('Ordinary cable')
            ->assertDontSee('Xbox Series S')
            ->assertDontSee('Gmail account setup');
    }

    public function test_sorting_toggles_direction_and_a_made_up_column_is_ignored(): void
    {
        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('sortBy', 'sale_price')
            ->assertSet('sort', 'sale_price')
            ->assertSet('direction', 'asc')
            ->call('sortBy', 'sale_price')
            ->assertSet('direction', 'desc')
            // A URL may ask for anything; only the four columns are honoured.
            ->call('sortBy', 'purchase_price); drop table products; --')
            ->assertSet('sort', 'sale_price');
    }

    public function test_selecting_the_page_moves_only_what_was_ticked(): void
    {
        $moved = $this->product(['name' => 'Moves']);
        $stays = $this->product(['name' => 'Stays']);
        $shelf = Category::create(['name' => 'Shelf']);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('selected', [$moved->id])
            ->call('assignCategory', $shelf->id);

        $this->assertSame($shelf->id, $moved->fresh()->category_id);
        $this->assertSame($this->category->id, $stays->fresh()->category_id);
    }

    public function test_narrowing_the_list_drops_a_selection_made_before_it(): void
    {
        $product = $this->product(['name' => 'USB Cable 2m']);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->set('selected', [$product->id])
            ->set('search', 'HDMI')
            ->assertSet('selected', []);
    }

    /** Section 5: history is somebody's invoice, so the row stays. */
    public function test_a_product_with_stock_history_is_deactivated_not_deleted(): void
    {
        $product = $this->product();

        // The real path in, so the movement is a real one rather than a row
        // shaped like one.
        app(StockAdjustmentService::class)->recordOpeningStock(
            product: $product, quantity: 4, unitCost: 1_000, user: $this->admin,
        );

        Livewire::actingAs($this->admin)->test(Index::class)->call('delete', $product->id);

        $this->assertNotSoftDeleted($product);
        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_a_product_that_never_moved_is_deleted(): void
    {
        $product = $this->product();

        Livewire::actingAs($this->admin)->test(Index::class)->call('delete', $product->id);

        $this->assertSoftDeleted($product);
    }

    // ---- The form -------------------------------------------------------

    public function test_saving_a_new_product_opens_its_first_fifo_layer(): void
    {
        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'Anker charger')
            ->set('category_id', $this->category->id)
            ->set('purchase_price', 12_000)
            ->set('sale_price', 18_000)
            ->set('opening_quantity', 6)
            ->set('opening_unit_cost', 11_500)
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Anker charger')->sole();

        $this->assertSame(6, $product->quantity);

        $batch = $product->stockBatches()->sole();
        $this->assertSame(6, $batch->quantity_remaining);
        // Section 1 rule 2: the batch cost is the figure that was typed.
        $this->assertSame(11_500, $batch->unit_cost);

        // Section 4: a blank code is generated rather than left empty.
        $this->assertNotEmpty($product->sku);
        $this->assertNotEmpty($product->barcode);
    }

    public function test_opening_stock_without_a_cost_is_refused(): void
    {
        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'Anker charger')
            ->set('category_id', $this->category->id)
            ->set('opening_quantity', 6)
            ->call('save')
            ->assertHasErrors(['opening_unit_cost' => 'required_with']);

        $this->assertDatabaseMissing('products', ['name' => 'Anker charger']);
    }

    public function test_a_sku_already_in_use_is_refused_while_the_cursor_is_still_in_the_box(): void
    {
        $this->product(['sku' => 'ANK-2211']);

        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('sku', 'ANK-2211')
            ->assertHasErrors(['sku' => 'unique']);
    }

    public function test_editing_keeps_the_products_own_sku(): void
    {
        $product = $this->product(['sku' => 'ANK-2211']);

        Livewire::actingAs($this->admin)->test(Form::class, ['product' => $product])
            ->assertSet('name', $product->name)
            ->set('sale_price', 2_000)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('ANK-2211', $product->fresh()->sku);
        $this->assertSame(2_000, $product->fresh()->sale_price);
    }

    /** Section 4: a barcode that fails its check digit will not scan at the till. */
    public function test_a_barcode_with_a_bad_check_digit_is_refused_as_it_is_typed(): void
    {
        // 6221031492754 checks out; changing the last digit breaks it.
        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('barcode', '6221031492755')
            ->assertHasErrors('barcode')
            ->set('barcode', '6221031492754')
            ->assertHasNoErrors('barcode');
    }

    public function test_the_margin_is_worked_out_as_the_prices_are_typed(): void
    {
        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('purchase_price', 12_000)
            ->set('sale_price', 18_000)
            ->assertSee('6,000')
            ->set('sale_price', 9_000)
            ->assertSee('Loss on each');
    }

    public function test_a_price_below_zero_is_refused(): void
    {
        Livewire::actingAs($this->admin)->test(Form::class)
            ->set('name', 'Anker charger')
            ->set('category_id', $this->category->id)
            ->set('sale_price', -1)
            ->call('save')
            ->assertHasErrors(['sale_price' => 'min']);
    }

    // ---- Permissions ----------------------------------------------------

    public function test_a_user_without_edit_cannot_move_products_between_categories(): void
    {
        // Section 2: two roles only, and a plain user starts with no
        // permissions until somebody grants them.
        $cashier = User::factory()->create(['role' => 'user']);
        $product = $this->product();
        $shelf = Category::create(['name' => 'Shelf']);

        Livewire::actingAs($cashier)->test(Index::class)
            ->set('selected', [$product->id])
            ->call('assignCategory', $shelf->id)
            ->assertForbidden();

        $this->assertSame($this->category->id, $product->fresh()->category_id);
    }
}
