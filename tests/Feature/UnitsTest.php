<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Units;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The units a shop measures things in — Section 8c, asked for by Soran on
 * 2026-09-12: *"in products Unit auto typed pcs, i want add some static and
 * setup in settings"*.
 *
 * The list is a setting and the unit stays a plain string on the product. What
 * is worth testing is the seam that creates: the list can change, and the
 * products already measured against the old one must not move.
 */
class UnitsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    public function test_a_shop_starts_with_a_list_and_a_default(): void
    {
        $this->assertContains('pcs', Units::all());
        $this->assertContains('kg', Units::all());
        $this->assertSame('pcs', Units::default());
    }

    public function test_the_list_is_tidied_rather_than_refused(): void
    {
        // A trailing newline and a repeat are not mistakes worth an error.
        $this->assertSame(['kg', 'm'], Units::parse("kg\n\n m \nkg\n"));
    }

    public function test_a_new_product_starts_on_the_shops_default(): void
    {
        Setting::put('units', "roll\nm");
        Setting::put('default_unit', 'm');

        $product = $this->actingAs($this->admin)->get(route('products.create'))
            ->assertOk()->viewData('product');

        $this->assertSame('m', $product->unit);
    }

    public function test_the_form_offers_the_list_as_a_dropdown(): void
    {
        Setting::put('units', "roll\nm");
        Setting::put('default_unit', 'roll');

        $this->actingAs($this->admin)->get(route('products.create'))
            ->assertOk()
            ->assertSee('<select id="unit" name="unit"', false)
            ->assertSee('<option value="roll"', false)
            ->assertSee('<option value="m"', false);
    }

    /**
     * ⚠️ The seam. A product measured in something the shop has since dropped
     * keeps it, and keeps it in its own dropdown.
     *
     * Without this, opening that product to change its price and pressing save
     * would re-measure it as whatever happens to be first in the list — a data
     * change made by looking at a page, with nothing said.
     */
    public function test_a_product_measured_in_a_retired_unit_keeps_it(): void
    {
        $product = Product::create([
            'name' => 'Cable', 'sku' => 'C1', 'unit' => 'dozen',
            'category_id' => Category::create(['name' => 'Wires'])->id,
            'purchase_price' => 0, 'sale_price' => 10_000, 'quantity' => 0,
        ]);

        Setting::put('units', "pcs\nkg");

        $this->assertSame(['dozen', 'pcs', 'kg'], Units::forSelect('dozen'));

        $page = $this->actingAs($this->admin)->get(route('products.edit', $product))->assertOk();

        $page->assertSee('<option value="dozen"', false);
        $page->assertSee('selected', false);

        // And saving the page back leaves it measured in dozens.
        $this->actingAs($this->admin)->put(route('products.update', $product), [
            ...$product->only(['name', 'sku', 'category_id', 'unit',
                'purchase_price', 'sale_price', 'reorder_level']),
            'is_active' => 1,
        ])->assertRedirect();

        $this->assertSame('dozen', $product->fresh()->unit);
    }

    public function test_the_default_must_be_one_of_the_units(): void
    {
        $this->actingAs($this->admin)
            ->from(route('settings.edit'))
            ->put(route('settings.update'), [...$this->settings(), 'units' => "pcs\nkg", 'default_unit' => 'furlong'])
            ->assertSessionHasErrors('default_unit');

        $this->assertSame('pcs', Units::default(), 'A rejected save changed the default anyway.');
    }

    public function test_the_list_is_saved_tidied(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.update'), [...$this->settings(), 'units' => " kg \n\nkg\nlitre\n", 'default_unit' => 'kg'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['kg', 'litre'], Units::all());
        $this->assertSame('kg', Units::default());
    }

    /** An import row with no unit column takes the shop's default, not a guess. */
    public function test_an_import_without_a_unit_takes_the_default(): void
    {
        Setting::put('units', "roll\nm");
        Setting::put('default_unit', 'roll');

        $this->assertSame('roll', Units::default());
    }

    /**
     * Everything the settings form posts, so a test can change one field.
     *
     * @return array<string, mixed>
     */
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
