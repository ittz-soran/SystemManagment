<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Support\InvoiceLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The invoice designer — PROJECT_DOC Section 9.
 *
 * Soran, 2026-10-20: *"should layers are changeable and sizes with fonts, for
 * ex i want use specific font, and products lines issuse -> some time product
 * have more one line -> for this case invoice need have line after full name
 * text then next line"*, and *"use best tool to design invoice inside
 * system"*. The dragging itself is the browser's and was driven there; these
 * hold what the server keeps, refuses and prints.
 */
class InvoiceDesignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        // A paper in place, as the designer would leave it.
        Storage::fake('local');
        Storage::disk('local')->put('branding/paper.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
        Setting::put('invoice_a5_paper', 'branding/paper.png');
        Setting::flushCache();
    }

    /** @param  array<string, mixed>  $changes  layer => fields */
    private function layout(array $changes = []): array
    {
        $layout = InvoiceLayout::DEFAULTS;

        foreach ($changes as $key => $fields) {
            $layout[$key] = array_merge($layout[$key] ?? [], $fields);
        }

        return $layout;
    }

    /** @param  array<string, mixed>  $extra */
    private function save(array $layout, array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('invoice-design.edit'))
            ->put(route('invoice-design.update'), ['layout' => json_encode($layout), ...$extra]);
    }

    private function sale(int $lines = 1): Sale
    {
        $category = Category::firstOrCreate(['name' => 'Parts']);
        $items = [];

        foreach (range(1, $lines) as $n) {
            $product = Product::create([
                'name' => 'Toner HP 85A no.'.$n, 'sku' => 'TN'.$n, 'category_id' => $category->id,
                'unit' => 'pcs', 'purchase_price' => 20_000, 'sale_price' => 35_000, 'quantity' => 0,
            ]);

            app(PurchaseService::class)->create(
                supplier: Supplier::firstOrCreate(['name' => 'Click Gaming']),
                lines: [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20_000]],
                user: $this->admin, purchaseDate: now(),
            );

            $items[] = ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 35_000];
        }

        $police = Customer::create(['name' => 'بەڕێوەبەرایەتی پۆلیسی پاریزگای هەڵەبجە', 'type' => Customer::GOVERNMENT, 'address' => 'Halabja']);

        return app(SaleService::class)->create(
            customer: $police, lines: $items, user: $this->admin, saleDate: now(), amountPaid: 0,
        );
    }

    private function printed(Sale $sale): string
    {
        return $this->actingAs($this->admin)->get(route('sales.print', $sale))->assertOk()->getContent();
    }

    /** The opening tag of one layer on the printed page, or null when it is not there. */
    private function layerTag(string $html, string $key): ?string
    {
        return preg_match('/<div class="inv-layer[^"]*"[^>]*data-layer="'.$key.'"[^>]*>/u', $html, $m) ? $m[0] : null;
    }

    // ---- the places ---------------------------------------------------------

    /** A shop that never opens the designer prints exactly what it did. */
    public function test_until_it_is_designed_the_invoice_prints_at_the_books_places(): void
    {
        $this->assertSame(InvoiceLayout::DEFAULTS, InvoiceLayout::current());

        $tag = $this->layerTag($this->printed($this->sale()), 'customer');
        $this->assertStringContainsString('--x: 21; --y: 54.5; --w: 103.7; --h: 5.4; --size: 10;', $tag);
    }

    public function test_a_moved_layer_is_kept_and_printed_where_it_was_put(): void
    {
        $this->save($this->layout(['customer' => ['x' => 30, 'y' => 56.04, 'size' => 12, 'bold' => true]]))
            ->assertSessionHasNoErrors();

        Setting::flushCache();
        $customer = InvoiceLayout::current()['customer'];
        $this->assertSame([30.0, 56.0, 12.0, true], [$customer['x'], $customer['y'], $customer['size'], $customer['bold']]);

        $tag = $this->layerTag($this->printed($this->sale()), 'customer');
        $this->assertStringContainsString('--x: 30; --y: 56; --w: 103.7; --h: 5.4; --size: 12;', $tag);
        $this->assertStringContainsString('is-bold', $tag);
    }

    public function test_the_item_lines_take_their_row_height_and_column_lines(): void
    {
        $this->save($this->layout(['lines' => ['row' => 9, 'columns' => [30, 45, 120]]]))->assertSessionHasNoErrors();

        $tag = $this->layerTag($this->printed($this->sale()), 'lines');
        $this->assertStringContainsString('--row: 9; --c1: 30; --c2: 45; --c3: 120;', $tag);
    }

    public function test_a_layer_off_the_sheet_is_refused(): void
    {
        $this->save($this->layout(['customer' => ['x' => 140, 'w' => 20]]))->assertSessionHasErrors('layout');

        Setting::flushCache();
        $this->assertSame(InvoiceLayout::DEFAULTS, InvoiceLayout::current());
    }

    public function test_column_lines_out_of_order_or_too_close_are_refused(): void
    {
        $this->save($this->layout(['lines' => ['columns' => [50, 40, 116]]]))->assertSessionHasErrors('layout');
        $this->save($this->layout(['lines' => ['columns' => [25, 27, 116]]]))->assertSessionHasErrors('layout');
        $this->save($this->layout(['lines' => ['columns' => [25, 40, 140]]]))->assertSessionHasErrors('layout');
    }

    public function test_sizes_and_row_heights_out_of_range_are_refused(): void
    {
        $this->save($this->layout(['words' => ['size' => 40]]))->assertSessionHasErrors('layout');
        $this->save($this->layout(['lines' => ['row' => 2]]))->assertSessionHasErrors('layout');
        $this->save($this->layout(['date' => ['w' => 1]]))->assertSessionHasErrors('layout');
    }

    public function test_a_layout_that_cannot_be_read_is_refused(): void
    {
        $this->actingAs($this->admin)->from(route('invoice-design.edit'))
            ->put(route('invoice-design.update'), ['layout' => '{not json'])
            ->assertSessionHasErrors('layout');

        $layout = $this->layout();
        unset($layout['total']);
        $this->save($layout)->assertSessionHasErrors('layout');
    }

    /** What is kept is the known layers and their known fields, nothing else. */
    public function test_unknown_layers_and_fields_are_not_kept(): void
    {
        $layout = $this->layout(['customer' => ['colour' => 'red']]);
        $layout['logo'] = ['x' => 1, 'y' => 1, 'w' => 10, 'h' => 10, 'size' => 10];

        $this->save($layout)->assertSessionHasNoErrors();

        $kept = json_decode((string) Setting::where('key', InvoiceLayout::SETTING)->value('value'), true);
        $this->assertSame(array_keys(InvoiceLayout::DEFAULTS), array_keys($kept));
        $this->assertArrayNotHasKey('colour', $kept['customer']);
    }

    /** A paper with no address line hides that layer; the item lines always print. */
    public function test_a_hidden_layer_is_not_printed_but_the_item_lines_always_are(): void
    {
        $this->save($this->layout(['address' => ['show' => false], 'lines' => ['show' => false]]))->assertSessionHasNoErrors();

        $html = $this->printed($this->sale());
        $this->assertNull($this->layerTag($html, 'address'));
        $this->assertNotNull($this->layerTag($html, 'lines'));
    }

    /** A setting broken by hand is the book's places, not an invoice that will not print. */
    public function test_a_broken_setting_prints_the_books_places(): void
    {
        Setting::put(InvoiceLayout::SETTING, '{cut short');
        Setting::flushCache();
        $this->assertSame(InvoiceLayout::DEFAULTS, InvoiceLayout::current());

        // One bad layer falls back alone.
        $layout = $this->layout(['date' => ['x' => 30]]);
        $layout['customer']['x'] = 'left';
        Setting::put(InvoiceLayout::SETTING, json_encode($layout));
        Setting::flushCache();

        $current = InvoiceLayout::current();
        $this->assertSame(InvoiceLayout::DEFAULTS['customer'], $current['customer']);
        $this->assertSame(30.0, $current['date']['x']);
    }

    // ---- the item rows ------------------------------------------------------

    /**
     * Every item goes to the browser once, a row each, the name free to wrap;
     * the browser measures them in the shop's font and moves what does not fit
     * on to more sheets. The total and its words are marked for the last.
     */
    public function test_every_item_is_sent_as_its_own_row_for_the_browser_to_lay_out(): void
    {
        $html = $this->printed($this->sale(lines: 13));

        $this->assertSame(1, substr_count($html, 'class="inv-sheet"'));
        $this->assertSame(13, substr_count($html, 'data-item'));
        $this->assertStringContainsString('<div class="inv-name" dir="auto">Toner HP 85A no.13</div>', $html);
        $this->assertStringContainsString('invoice-print', $html);
        $this->assertMatchesRegularExpression('/data-layer="words"\s+data-last-only/', $html);
        $this->assertMatchesRegularExpression('/data-layer="total"\s+data-last-only/', $html);
    }

    // ---- the font -----------------------------------------------------------

    public function test_a_font_is_kept_by_what_it_is_and_sent_behind_the_login(): void
    {
        $this->save($this->layout(), [
            'invoice_font' => UploadedFile::fake()->createWithContent('Rabar.ttf', "\x00\x01\x00\x00".str_repeat("\x00", 64)),
        ])->assertSessionHasNoErrors();

        Setting::flushCache();
        $this->assertStringEndsWith('.ttf', (string) setting('invoice_font'));

        $response = $this->actingAs($this->admin)->get(route('branding.font'))->assertOk();
        $this->assertSame('font/ttf', $response->headers->get('content-type'));
        $this->assertStringContainsString('private', $response->headers->get('cache-control'));

        auth()->logout();
        $this->get(route('branding.font'))->assertRedirect(route('login'));
    }

    /** Named .woff2 or not, a WOFF2 file is kept and sent as one. */
    public function test_a_font_is_named_by_its_first_bytes_not_its_name(): void
    {
        $this->save($this->layout(), [
            'invoice_font' => UploadedFile::fake()->createWithContent('Speda.ttf', 'wOF2'.str_repeat("\x00", 64)),
        ])->assertSessionHasNoErrors();

        Setting::flushCache();
        $this->assertStringEndsWith('.woff2', (string) setting('invoice_font'));
        $this->assertSame('font/woff2', $this->actingAs($this->admin)->get(route('branding.font'))->headers->get('content-type'));
    }

    public function test_a_file_that_is_not_a_font_is_refused(): void
    {
        $this->save($this->layout(), [
            'invoice_font' => UploadedFile::fake()->createWithContent('Rabar.ttf', '<?php echo "not a font";'),
        ])->assertSessionHasErrors('invoice_font');

        Setting::flushCache();
        $this->assertNull(setting('invoice_font'));
    }

    public function test_a_new_font_replaces_the_old_file(): void
    {
        Storage::disk('local')->put('branding/old.otf', 'OTTO');
        Setting::put('invoice_font', 'branding/old.otf');
        Setting::flushCache();

        $this->save($this->layout(), [
            'invoice_font' => UploadedFile::fake()->createWithContent('new.otf', 'OTTO'.str_repeat("\x00", 64)),
        ])->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing('branding/old.otf');
    }

    /** On the paper, and on the drawn page a shop without a paper prints. */
    public function test_the_font_is_used_on_both_government_invoices(): void
    {
        Storage::disk('local')->put('branding/font.woff', 'wOFF');
        Setting::put('invoice_font', 'branding/font.woff');
        Setting::flushCache();
        $sale = $this->sale();

        $this->assertStringContainsString(route('branding.font'), $this->printed($sale));

        Setting::put('invoice_a5_paper', null);
        Setting::flushCache();
        $drawn = $this->printed($sale);
        $this->assertStringContainsString('Fault goes back to both sides.', $drawn);
        $this->assertStringContainsString(route('branding.font'), $drawn);
    }

    // ---- the paper ----------------------------------------------------------

    public function test_the_paper_is_uploaded_in_the_designer_and_replaces_the_last_one(): void
    {
        $this->save($this->layout(), [
            'invoice_a5_paper' => UploadedFile::fake()->image('a5.png', 1748, 2481),
        ])->assertSessionHasNoErrors();

        Setting::flushCache();
        $this->assertNotSame('branding/paper.png', setting('invoice_a5_paper'));
        Storage::disk('local')->assertMissing('branding/paper.png');
        Storage::disk('local')->assertExists((string) setting('invoice_a5_paper'));
    }

    /** A PDF cannot be laid under a page here; the shop sends a picture of it. */
    public function test_a_paper_that_is_not_a_picture_is_refused(): void
    {
        $this->save($this->layout(), [
            'invoice_a5_paper' => UploadedFile::fake()->create('A5.pdf', 270, 'application/pdf'),
        ])->assertSessionHasErrors('invoice_a5_paper');

        Setting::flushCache();
        $this->assertSame('branding/paper.png', setting('invoice_a5_paper'));
    }

    // ---- the page -----------------------------------------------------------

    /** The layers are shown filled with the shop's last government sale. */
    public function test_the_designer_shows_the_last_government_sale(): void
    {
        $sale = $this->sale(lines: 2);

        $this->actingAs($this->admin)->get(route('invoice-design.edit'))
            ->assertOk()
            ->assertSee('Toner HP 85A no.2')
            ->assertSee(route('sales.print', $sale), false)
            ->assertSee('invoice-designer', false);
    }

    /**
     * ⚠️ Found by driving it: a font over the host's upload limit was refused
     * and the page came back with the saved places, every move thrown away.
     */
    public function test_after_a_refused_save_the_designer_keeps_what_was_on_screen(): void
    {
        $moved = $this->layout(['customer' => ['x' => 33.5]]);

        $this->save($moved, [
            'invoice_font' => UploadedFile::fake()->createWithContent('Rabar.ttf', 'not a font'),
        ])->assertSessionHasErrors('invoice_font');

        $page = $this->actingAs($this->admin)->get(route('invoice-design.edit'))->getContent();
        preg_match('/id="design-layout" value="([^"]*)"/', $page, $m);
        $this->assertSame(33.5, json_decode(html_entity_decode($m[1]), true)['customer']['x']);

        // And nothing of it was kept.
        Setting::flushCache();
        $this->assertSame(InvoiceLayout::DEFAULTS, InvoiceLayout::current());
    }

    public function test_only_a_settings_manager_designs_the_invoice(): void
    {
        $staff = User::create([
            'name' => 'Till', 'email' => 'till@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_USER,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);
        $staff->permissions()->sync(Permission::where('key', 'sales.view')->pluck('id')->all());

        $this->actingAs($staff)->get(route('invoice-design.edit'))->assertForbidden();
        $this->actingAs($staff)->put(route('invoice-design.update'), ['layout' => json_encode($this->layout())])->assertForbidden();
    }

    public function test_settings_leads_to_the_designer(): void
    {
        $this->actingAs($this->admin)->get(route('settings.edit'))
            ->assertOk()
            ->assertSee(route('invoice-design.edit'), false);
    }
}
