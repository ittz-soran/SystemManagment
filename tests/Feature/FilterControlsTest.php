<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The category filter, and why it stopped being a `<select multiple>`.
 *
 * **Soran wrote "fix his input sizes" beside this, 2026-09-12.** Measured, the
 * control was 40px tall between two 31px fields — but the size was the symptom.
 * A `multiple` select is a LIST BOX, and no browser renders one as a dropdown
 * however small you ask: Chrome gave it the extra height, and iOS Safari — an
 * iPad behind a counter being where this shop is actually run — collapsed it to
 * a box reading **"0 Items"**, which is the operating system's own wording and
 * says nothing about categories at all.
 *
 * What matters in these tests is that swapping the control changed nothing
 * behind the screen: the same field name, the same values, the same filtering.
 */
class FilterControlsTest extends TestCase
{
    use RefreshDatabase;

    private function shelf(): array
    {
        $this->seed();

        $phones = Category::firstOrCreate(['name' => 'Mobile accessories']);
        $computers = Category::firstOrCreate(['name' => 'Computer accessories']);

        foreach ([['Charger cable', 'A1', $phones], ['Keyboard', 'B1', $computers]] as [$name, $sku, $cat]) {
            Product::create([
                'name' => $name, 'sku' => $sku, 'category_id' => $cat->id, 'unit' => 'pcs',
                'purchase_price' => 100, 'sale_price' => 200, 'quantity' => 1,
            ]);
        }

        return [User::where('email', 'admin@example.com')->firstOrFail(), $phones, $computers];
    }

    public function test_it_still_filters_by_one_category(): void
    {
        [$admin, $phones] = $this->shelf();

        $this->actingAs($admin)->get(route('products.index', ['categories' => [$phones->id]]))
            ->assertOk()
            ->assertSee('Charger cable')
            ->assertDontSee('Keyboard');
    }

    /** And by several, which is the whole reason it is not a plain select. */
    public function test_it_still_filters_by_several(): void
    {
        [$admin, $phones, $computers] = $this->shelf();

        $this->actingAs($admin)->get(route('products.index', ['categories' => [$phones->id, $computers->id]]))
            ->assertOk()
            ->assertSee('Charger cable')
            ->assertSee('Keyboard');
    }

    /** ⚠️ The control the operating system rendered as "0 Items" is gone. */
    public function test_the_multiple_select_is_gone(): void
    {
        [$admin] = $this->shelf();

        $body = $this->actingAs($admin)->get(route('products.index'))->getContent();

        $this->assertStringNotContainsString('name="categories[]" multiple', $body);
        $this->assertStringNotContainsString('multiple size="1"', $body);
    }

    /** It says what is chosen in the shop's own words, at every count. */
    public function test_it_says_what_is_chosen(): void
    {
        [$admin, $phones, $computers] = $this->shelf();

        $this->actingAs($admin)->get(route('products.index'))
            ->assertSee(__('All categories'));

        $this->actingAs($admin)->get(route('products.index', ['categories' => [$phones->id]]))
            ->assertSee('Mobile accessories');

        // Three or more becomes a count — a button cannot hold nine names.
        $third = Category::create(['name' => 'Cables']);

        $this->actingAs($admin)
            ->get(route('products.index', ['categories' => [$phones->id, $computers->id, $third->id]]))
            ->assertSee(trans_choice(':count chosen', 3, ['count' => 3]));
    }
}
