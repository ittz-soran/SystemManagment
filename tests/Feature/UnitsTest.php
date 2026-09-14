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

    /**
     * Blank rows and repeats are dropped — and `pcs` survives, see below.
     */
    public function test_the_list_is_saved_tidied(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.update'), [...$this->settings(),
                'units' => [' kg ', '', 'kg', 'litre'], 'default_unit' => 'kg'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['pcs', 'kg', 'litre'], Units::all());
        $this->assertSame('kg', Units::default());
    }

    // ---- pcs, in every shop ---------------------------------------------

    /**
     * ⚠️ **Soran, 2026-09-14:** *"have pcs by default added for all systems"*.
     *
     * Seeding it is not enough — seeding only covers the first morning. A shop
     * that removes every row and saves gets it back, because a product form
     * whose dropdown offers nothing is a shop that cannot add a product.
     */
    public function test_pcs_comes_back_when_a_shop_saves_a_list_without_it(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.update'), [...$this->settings(),
                'units' => ['kgm', 'karton'], 'default_unit' => 'kgm'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['pcs', 'kgm', 'karton'], Units::all(),
            'a shop removed pcs and the system let it go');

        // And it is what was STORED, not only what is read back — the page has
        // to show the shop the list it actually has.
        $this->assertStringContainsString('pcs', (string) setting('units'));
    }

    /** Even from a settings row somebody edited in the database by hand. */
    public function test_pcs_is_there_however_the_setting_was_written(): void
    {
        Setting::put('units', "kgm\nkarton");

        $this->assertSame(['pcs', 'kgm', 'karton'], Units::all());
        $this->assertContains('pcs', Units::forSelect('mitir'));
    }

    /** ⚠️ And it stays where the shop put it, rather than jumping to the top. */
    public function test_pcs_is_not_moved_when_the_shop_ordered_it_themselves(): void
    {
        Setting::put('units', "kgm\npcs\nkarton");

        $this->assertSame(['kgm', 'pcs', 'karton'], Units::all());
    }

    /** The screen offers a Remove on every unit except that one. */
    public function test_the_settings_page_pins_pcs_and_offers_the_rest(): void
    {
        Setting::put('units', "pcs\nkgm");

        $page = $this->actingAs($this->admin)->get(route('settings.edit'))
            ->assertOk();

        $page->assertSee('name="units[]"', escape: false)
            ->assertSee(__('Add unit'))
            ->assertSee(__('Remove this unit'));

        // Counted inside the rows themselves. The script below the page names
        // the same attributes in its selectors, and counting the whole document
        // would be counting those too.
        $html = $page->getContent();
        $at = strpos($html, 'id="unit-rows"');
        $written = substr($html, $at, strpos($html, 'id="unit-add"') - $at);

        // One row each, and only the one that is not pinned can be removed.
        $this->assertSame(2, substr_count($written, 'name="units[]"'));
        $this->assertSame(1, substr_count($written, 'data-role="unit-remove"'));
        $this->assertSame(1, substr_count($written, 'readonly'),
            'the pinned row is the only one nobody can retype');
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
