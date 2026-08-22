<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\LabelPrinter;
use App\Services\LabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Printing a shelf label.
 *
 * Section 4 gives an auto-generated barcode an internal EAN-13 prefix, so
 * nothing is printed on the goods and the shop has to make the label itself.
 *
 * The constraint that shapes the whole feature: a web page cannot choose a
 * printer. window.print() hands the job to the operating system and the person
 * picks there. Sending a label straight to a printer is only possible because
 * the server runs on the same machine — so the browser route is the one that
 * always works, and the direct one is the shortcut.
 */
class BarcodeLabelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
            'barcode' => '2000000000015',
        ]);
    }

    // -------------------------------------------------------------- the label

    public function test_the_sheet_is_one_page_per_copy_at_the_labels_real_size(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('products.label', [$this->product, 'copies' => 3, 'size' => '50x30']))
            ->assertOk()
            ->getContent();

        // The page IS the label: no margin, or the printer wastes one and the
        // next label starts halfway down the following one.
        $this->assertStringContainsString('size: 50mm 30mm', $html);
        $this->assertStringContainsString('margin: 0', $html);

        $this->assertSame(3, substr_count($html, 'class="label"'));
        $this->assertStringContainsString('page-break-after: always', $html);
    }

    public function test_the_barcode_is_drawn_at_a_size_in_millimetres(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('products.label', [$this->product, 'size' => '50x30']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<svg', $html);

        // Millimetres, not pixels: a screen's pixel density has nothing to do
        // with how wide the bars come out of the printer.
        //
        // 38.67mm rather than the full 46mm of usable label: the rest is the
        // quiet zone the bars need either side to be findable at all.
        $this->assertMatchesRegularExpression('/<svg width="38.67mm" height="11mm"/', $html);
    }

    /**
     * Bars printed edge to edge look tidier and read far worse. EAN-13 wants 11
     * modules of clear background before it and 7 after, against 95 of bars.
     */
    public function test_the_bars_leave_a_quiet_zone_on_both_sides(): void
    {
        $labels = app(LabelService::class);

        foreach (['50x30', '40x30', '30x20'] as $key) {
            $size = config('labels.sizes.'.$key);
            $usable = $size['width'] - 2 * $size['padding'];
            $bars = $labels->barcodeWidth('2000000000015', $size);

            $this->assertLessThan($usable, $bars, "{$key} runs the bars edge to edge");
            $this->assertEqualsWithDelta($usable * 95 / 113, $bars, 0.01, $key);
        }

        // A code that is not an EAN-13 still gets one.
        $this->assertLessThan(
            46,
            $labels->barcodeWidth('ABC-123', config('labels.sizes.50x30')),
        );
    }

    public function test_the_chosen_fields_are_the_ones_printed(): void
    {
        $only = $this->actingAs($this->admin)
            ->get(route('products.label', [$this->product, 'fields' => ['name']]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('USB 32GB', $only);
        $this->assertStringNotContainsString('USB32', $only, 'The SKU was not ticked');
        $this->assertStringNotContainsString('15,000', $only, 'The price was not ticked');

        $all = $this->actingAs($this->admin)
            ->get(route('products.label', [
                $this->product,
                'fields' => ['name', 'sku', 'price', 'barcode_number'],
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('USB32', $all);
        $this->assertStringContainsString('15,000', $all);
        $this->assertStringContainsString('2000000000015', $all);
    }

    /**
     * Unticking everything must mean nothing, not "fall back to the defaults" —
     * or a field could never be turned off for one print run.
     */
    public function test_unticking_every_field_prints_only_the_bars(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('products.label', [$this->product, 'chose_fields' => 1]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('USB32', $html);
        $this->assertStringNotContainsString('15,000', $html);
    }

    public function test_the_defaults_come_from_settings_when_nothing_is_chosen(): void
    {
        // '0' is off. A null would mean "nothing saved", which falls back to
        // the shipped default — that distinction is what the settings form
        // relies on to make a checkbox switchable at all.
        Setting::put('label_show_price', '0');
        Setting::put('label_show_name', '1');
        Setting::flushCache();

        $html = $this->actingAs($this->admin)
            ->get(route('products.label', $this->product))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('USB 32GB', $html);
        $this->assertStringNotContainsString('15,000', $html);
    }

    /** A name wider than the label would push the bars off it. */
    public function test_a_long_name_is_shortened_to_fit(): void
    {
        $this->product->forceFill(['name' => str_repeat('Very long product name ', 5)])->save();

        $html = $this->actingAs($this->admin)
            ->get(route('products.label', [$this->product, 'size' => '30x20', 'fields' => ['name']]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('…', $html);

        // Once, in the <title>. The label itself carries the shortened form.
        $this->assertSame(1, substr_count($html, str_repeat('Very long product name ', 5)));
    }

    /** Section 9b: warn, never block. A small label is a legitimate choice. */
    public function test_a_label_too_narrow_for_the_bars_warns_rather_than_refusing(): void
    {
        $this->actingAs($this->admin)
            ->get(route('products.label', [$this->product, 'size' => '30x20']))
            ->assertOk()
            ->assertSee('may miss them', false);

        $this->actingAs($this->admin)
            ->get(route('products.label', [$this->product, 'size' => '50x30']))
            ->assertOk()
            ->assertDontSee('may miss them', false);
    }

    public function test_a_product_with_no_barcode_is_sent_back_rather_than_printing_nothing(): void
    {
        $bare = Product::create([
            'name' => 'No code', 'sku' => 'NC1', 'category_id' => $this->product->category_id,
            'unit' => 'pcs', 'purchase_price' => 1, 'sale_price' => 2, 'quantity' => 0,
        ]);

        $this->actingAs($this->admin)
            ->get(route('products.label', $bare))
            ->assertRedirect(route('products.show', $bare))
            ->assertSessionHas('error');
    }

    public function test_an_unknown_label_size_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('products.label', [$this->product, 'size' => '999x999']))
            ->assertSessionHasErrors('size');
    }

    // ------------------------------------------------------------- the printer

    public function test_tspl_carries_the_size_the_code_and_the_copies(): void
    {
        $labels = app(LabelService::class);

        $tspl = $labels->tspl($labels->spec($this->product, '50x30', [
            'name' => true, 'sku' => true, 'price' => true, 'barcode_number' => true, 'shop' => false,
        ], 4));

        $this->assertStringContainsString('SIZE 50 mm,30 mm', $tspl);
        $this->assertStringContainsString('BARCODE ', $tspl);
        $this->assertStringContainsString('"EAN13"', $tspl);
        $this->assertStringContainsString('2000000000015', $tspl);
        $this->assertStringContainsString('USB 32GB', $tspl);
        $this->assertStringContainsString('PRINT 4,1', $tspl);

        // TSPL wants CRLF; a bare newline leaves some firmware waiting.
        $this->assertStringContainsString("\r\n", $tspl);
    }

    /** A typed manufacturer code is not an EAN-13, and must still print. */
    public function test_a_non_ean_code_falls_back_to_code_128(): void
    {
        $this->product->forceFill(['barcode' => 'ABC-123-XYZ'])->save();

        $labels = app(LabelService::class);
        $tspl = $labels->tspl($labels->spec($this->product->refresh(), '50x30'));

        $this->assertStringContainsString('"128"', $tspl);

        $this->actingAs($this->admin)
            ->get(route('products.label', $this->product))
            ->assertOk()
            ->assertSee('<svg', false);
    }

    public function test_direct_printing_is_offered_only_once_a_printer_is_set_up(): void
    {
        $printer = app(LabelPrinter::class);

        $this->assertFalse($printer->isConfigured());
        $this->assertNull($printer->target());

        Setting::put('label_printer', '\\\\localhost\\XP-365B');
        Setting::flushCache();

        $this->assertTrue(app(LabelPrinter::class)->isConfigured());
        $this->assertSame('\\\\localhost\\XP-365B', app(LabelPrinter::class)->target());
    }

    public function test_printing_direct_without_a_printer_says_so_instead_of_failing_silently(): void
    {
        $this->actingAs($this->admin)
            ->from(route('products.show', $this->product))
            ->post(route('products.label.print', $this->product), ['copies' => 2])
            ->assertRedirect(route('products.show', $this->product))
            ->assertSessionHas('error');

        $this->assertStringContainsString(
            'No printer is set up',
            (string) session('error'),
        );
    }

    public function test_an_unreachable_printer_names_itself_in_the_error(): void
    {
        Setting::put('label_printer', '/proc/nope/not-a-printer');
        Setting::flushCache();

        $this->actingAs($this->admin)
            ->from(route('products.show', $this->product))
            ->post(route('products.label.print', $this->product), ['copies' => 1])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringContainsString('not-a-printer', (string) session('error'));
    }

    // -------------------------------------------------------------- the screens

    public function test_the_product_page_offers_the_modal(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('Print barcode'), $html);
        $this->assertStringContainsString('id="label-modal"', $html);
        $this->assertStringContainsString(route('products.label.print', $this->product), $html);

        // Every choice the modal offers.
        foreach (LabelService::FIELDS as $field) {
            $this->assertStringContainsString('value="'.$field.'"', $html);
        }
    }

    public function test_a_product_without_a_barcode_gets_no_button(): void
    {
        $bare = Product::create([
            'name' => 'No code', 'sku' => 'NC1', 'category_id' => $this->product->category_id,
            'unit' => 'pcs', 'purchase_price' => 1, 'sale_price' => 2, 'quantity' => 0,
        ]);

        $this->actingAs($this->admin)
            ->get(route('products.show', $bare))
            ->assertOk()
            ->assertDontSee('id="label-modal"', false);
    }

    public function test_the_settings_page_sets_the_defaults(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.edit'))
            ->assertOk()
            ->assertSee(__('Barcode labels'))
            ->assertSee(__('Label printer'))
            // The constraint, said where someone configuring it will read it.
            ->assertSee('cannot choose a printer', false);
    }

    /** A checkbox posts nothing when unticked, so absence has to mean off. */
    public function test_a_default_field_can_be_turned_off_and_stays_off(): void
    {
        $this->assertTrue(app(LabelService::class)->fields()['price']);

        $this->actingAs($this->admin)
            ->put(route('settings.update'), $this->settingsPayload([
                'label_show_name' => '1',
                // price deliberately absent, as an unticked box would be
            ]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();

        $this->assertFalse(app(LabelService::class)->fields()['price']);
        $this->assertTrue(app(LabelService::class)->fields()['name']);
    }

    public function test_viewing_a_label_needs_permission_to_see_products(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->get(route('products.label', $this->product))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'shop_name' => 'Soran Store',
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
            'backup_frequency' => 'daily',
            'backup_time' => '02:15',
            'backup_weekday' => 5,
            'backup_keep_daily' => 30,
            'backup_keep_monthly' => 12,
            'label_size' => '50x30',
        ], $overrides);
    }
}
