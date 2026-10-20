<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Support\AmountInWords;
use App\Support\GovernmentInvoice;
use App\Support\InvoiceLayout;
use App\Support\InvoiceOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What the A5 government invoice says, and how — PROJECT_DOC Section 9.
 *
 * Soran, 2026-10-20: *"add more changeable -> set numbers language -> have
 * curency Symbol or not -> text color -> INV-009 show as 009 or not -> if one
 * of Layers not text show as " - " or empty " " -> in kurdish 1 psc is "١ دانە"
 * or just show " ١ ""*, and *"for 674,000 should show "تەنها شەش سەد و حەفتا و
 * چوار هەزار دینار""*.
 */
class InvoiceOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        Storage::fake('local');
        Storage::disk('local')->put('branding/paper.png', 'png');
        Setting::put('invoice_a5_paper', 'branding/paper.png');
        Setting::flushCache();
    }

    /** One line: 2 × "Toner HP 85A" at 35,000 to a department with no address. */
    private function sale(string $unit = 'pcs', ?string $address = null): Sale
    {
        $product = Product::create([
            'name' => 'Toner HP 85A', 'sku' => 'TN1', 'category_id' => Category::firstOrCreate(['name' => 'Parts'])->id,
            'unit' => $unit, 'purchase_price' => 20_000, 'sale_price' => 35_000, 'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::firstOrCreate(['name' => 'Click Gaming']),
            lines: [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20_000]],
            user: $this->admin, purchaseDate: now(),
        );

        $police = Customer::create(['name' => 'Halabja Police', 'type' => Customer::GOVERNMENT, 'address' => $address]);

        return app(SaleService::class)->create(
            customer: $police, lines: [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 35_000]],
            user: $this->admin, saleDate: now()->setDate(2026, 10, 20), amountPaid: 0,
        );
    }

    /** @param  array<string, mixed>  $options */
    private function save(array $options, ?array $layout = null): TestResponse
    {
        return $this->actingAs($this->admin)
            ->from(route('invoice-design.edit'))
            ->put(route('invoice-design.update'), [
                'layout' => json_encode($layout ?? InvoiceLayout::DEFAULTS),
                'options' => json_encode([...InvoiceOptions::DEFAULTS, ...$options]),
            ]);
    }

    /** @return array<string, string> layer => its text on the printed page */
    private function printed(Sale $sale): array
    {
        $html = $this->actingAs($this->admin)->get(route('sales.print', $sale))->assertOk()->getContent();

        preg_match_all('/data-layer="(\w+)"[^>]*>\s*<span class="inv-value">([^<]*)</u', $html, $layers, PREG_SET_ORDER);
        preg_match_all('/data-cell="(\w+)">([^<]*)</u', $html, $cells, PREG_SET_ORDER);

        return [
            ...collect($layers)->mapWithKeys(fn ($m) => [$m[1] => html_entity_decode($m[2])])->all(),
            ...collect($cells)->mapWithKeys(fn ($m) => [$m[1] => html_entity_decode($m[2])])->all(),
        ];
    }

    // ---- what it printed before, until the shop says otherwise ---------------

    public function test_until_it_is_changed_the_invoice_says_what_it_did(): void
    {
        $this->assertSame(InvoiceOptions::DEFAULTS, InvoiceOptions::current());

        $text = $this->printed($sale = $this->sale());

        $this->assertSame('2026-10-20', $text['date']);
        $this->assertSame('2 pcs', $text['qty']);
        $this->assertSame('35,000', $text['price']);
        $this->assertSame('70,000 IQD', $text['total']);
        $this->assertSame($sale->document_no, $text['number']);
        $this->assertSame('', $text['address']);
    }

    // ---- numbers --------------------------------------------------------------

    /** The figures only; a name, a model number, an address stay as typed. */
    public function test_kurdish_numbers_change_the_figures_and_nothing_else(): void
    {
        $this->save(['digits' => 'arabic'])->assertSessionHasNoErrors();

        $text = $this->printed($sale = $this->sale());

        $this->assertSame('٢٠٢٦-١٠-٢٠', $text['date']);
        $this->assertSame('٣٥,٠٠٠', $text['price']);
        $this->assertSame('٢ pcs', $text['qty']);
        $this->assertSame('٧٠,٠٠٠', $text['amount']);
        $this->assertSame('٧٠,٠٠٠ IQD', $text['total']);
        $this->assertSame(preg_replace_callback('/\d/', fn ($d) => mb_substr('٠١٢٣٤٥٦٧٨٩', (int) $d[0], 1), $sale->document_no), $text['number']);
        $this->assertSame('Toner HP 85A', $text['name']);
        $this->assertSame('Halabja Police', $text['customer']);
    }

    public function test_persian_numbers_are_persian_digits(): void
    {
        $this->save(['digits' => 'persian'])->assertSessionHasNoErrors();

        $this->assertSame('۲۰۲۶-۱۰-۲۰', $this->printed($this->sale())['date']);
    }

    // ---- currency, number, empty -----------------------------------------------

    public function test_the_total_without_its_currency_symbol(): void
    {
        $this->save(['currency' => false])->assertSessionHasNoErrors();

        $this->assertSame('70,000', $this->printed($this->sale())['total']);
    }

    /** INV-00009 → 00009, the zeros kept. */
    public function test_the_invoice_number_without_its_letters(): void
    {
        $this->save(['number' => 'number'])->assertSessionHasNoErrors();

        $sale = $this->sale();
        $this->assertSame(preg_replace('/^\D+/', '', $sale->document_no), $this->printed($sale)['number']);
        $this->assertMatchesRegularExpression('/^\d+$/', $this->printed($sale)['number']);
    }

    public function test_a_layer_with_nothing_to_say_shows_a_dash_or_nothing(): void
    {
        $this->assertSame('', $this->printed($sale = $this->sale())['address']);

        $this->save(['empty' => 'dash'])->assertSessionHasNoErrors();
        $this->assertSame('-', $this->printed($sale)['address']);
    }

    /** A dash only stands in for nothing; an address that is there is printed. */
    public function test_a_dash_does_not_replace_what_is_there(): void
    {
        $this->save(['empty' => 'dash'])->assertSessionHasNoErrors();

        $this->assertSame('Halabja', $this->printed($this->sale(address: 'Halabja'))['address']);
    }

    // ---- the quantity ----------------------------------------------------------

    /** "١ دانە" — Kurdish numbers and the invoice's own word for the unit. */
    public function test_the_unit_takes_the_invoices_own_word(): void
    {
        $this->save(['digits' => 'arabic', 'units' => ['pcs' => 'دانە']])->assertSessionHasNoErrors();

        $this->assertSame('٢ دانە', $this->printed($this->sale())['qty']);
    }

    /** Or "١" alone. */
    public function test_the_quantity_without_its_unit(): void
    {
        $this->save(['digits' => 'arabic', 'unit' => false, 'units' => ['pcs' => 'دانە']])->assertSessionHasNoErrors();

        $this->assertSame('٢', $this->printed($this->sale())['qty']);
    }

    /** A unit with no word of its own prints as the shop typed it. */
    public function test_a_unit_without_a_word_prints_as_typed(): void
    {
        $this->save(['units' => ['pcs' => 'دانە']])->assertSessionHasNoErrors();

        $this->assertSame('2 box', $this->printed($this->sale(unit: 'box'))['qty']);
    }

    /** ⚠️ PHP makes the key '10' the integer 10; the unit still finds its word. */
    public function test_a_unit_that_is_all_digits_keeps_its_word(): void
    {
        $this->save(['units' => ['10' => 'دە دانەیی']])->assertSessionHasNoErrors();

        Setting::flushCache();
        $this->assertSame(['10' => 'دە دانەیی'], InvoiceOptions::current()['units']);
        $this->assertSame('2 دە دانەیی', $this->printed($this->sale(unit: '10'))['qty']);
    }

    /** Only words that say something are kept. */
    public function test_empty_unit_words_are_not_kept(): void
    {
        $this->save(['units' => ['pcs' => '  ', 'box' => 'قوتوو']])->assertSessionHasNoErrors();

        Setting::flushCache();
        $this->assertSame(['box' => 'قوتوو'], InvoiceOptions::current()['units']);
    }

    // ---- refused ---------------------------------------------------------------

    public function test_options_it_does_not_know_are_refused(): void
    {
        $this->save(['digits' => 'roman'])->assertSessionHasErrors('options');
        $this->save(['currency' => 'yes'])->assertSessionHasErrors('options');
        $this->save(['units' => ['pcs' => str_repeat('د', 31)]])->assertSessionHasErrors('options');

        Setting::flushCache();
        $this->assertSame(InvoiceOptions::DEFAULTS, InvoiceOptions::current());
    }

    /** A setting broken by hand is the starting values, not an invoice that will not print. */
    public function test_a_broken_setting_prints_as_it_started(): void
    {
        Setting::put(InvoiceOptions::SETTING, '{"digits": "klingon"');
        Setting::flushCache();

        $this->assertSame(InvoiceOptions::DEFAULTS, InvoiceOptions::current());
        $this->assertSame('2 pcs', $this->printed($this->sale())['qty']);
    }

    public function test_after_a_refused_save_the_designer_keeps_the_options_on_screen(): void
    {
        $layout = InvoiceLayout::DEFAULTS;
        $layout['date']['x'] = 300;

        $this->save(['digits' => 'arabic'], $layout)->assertSessionHasErrors('layout');

        $page = $this->actingAs($this->admin)->get(route('invoice-design.edit'))->getContent();
        preg_match('/id="design-options" value="([^"]*)"/', $page, $m);
        $this->assertSame('arabic', json_decode(html_entity_decode($m[1]), true)['digits']);
    }

    // ---- colour ----------------------------------------------------------------

    public function test_each_layer_prints_in_its_own_colour(): void
    {
        $layout = InvoiceLayout::DEFAULTS;
        $layout['words']['color'] = '#C00000';

        $this->save([], $layout)->assertSessionHasNoErrors();

        Setting::flushCache();
        $this->assertSame('#c00000', InvoiceLayout::current()['words']['color']);
        $this->assertSame(InvoiceLayout::INK, InvoiceLayout::current()['total']['color']);

        $html = $this->actingAs($this->admin)->get(route('sales.print', $this->sale()))->getContent();
        $this->assertMatchesRegularExpression('/data-layer="words"[^>]*--color: #c00000;/', $html);
    }

    public function test_a_colour_that_is_not_a_colour_is_refused(): void
    {
        $layout = InvoiceLayout::DEFAULTS;
        $layout['words']['color'] = 'red; background: url(x)';

        $this->save([], $layout)->assertSessionHasErrors('layout');
    }

    /** A layout saved before there was a colour reads as black. */
    public function test_a_layout_from_before_colours_prints_black(): void
    {
        $layout = InvoiceLayout::DEFAULTS;
        unset($layout['customer']['color']);
        $layout['customer']['x'] = 30;
        Setting::put(InvoiceLayout::SETTING, json_encode($layout));
        Setting::flushCache();

        $current = InvoiceLayout::current()['customer'];
        $this->assertSame([30.0, InvoiceLayout::INK], [$current['x'], $current['color']]);
    }

    // ---- the words ---------------------------------------------------------------

    /** Soran: "for 674,000 should show "تەنها شەش سەد و حەفتا و چوار هەزار دینار"". */
    public function test_kurdish_hundreds_are_two_words(): void
    {
        app()->setLocale('en');

        $this->assertSame('تەنها شەش سەد و حەفتا و چوار هەزار دینار', GovernmentInvoice::words(674_000));
        $this->assertSame('تەنها نۆ سەد هەزار دینار', GovernmentInvoice::words(900_000));
        $this->assertSame('en', app()->getLocale());

        // The same list writes the sale screen's line, so it changes there too.
        app()->setLocale('ckb');
        $this->assertSame('دوو سەد و پەنجا هەزار دینار', AmountInWords::for(250_000));
    }

    // ---- the page --------------------------------------------------------------

    public function test_the_designer_offers_every_option_and_every_unit(): void
    {
        $this->sale(unit: 'box');

        $page = $this->actingAs($this->admin)->get(route('invoice-design.edit'))->assertOk();

        foreach (['digits', 'number', 'empty', 'currency', 'unit'] as $option) {
            $page->assertSee('data-option="'.$option.'"', false);
        }
        $page->assertSee('data-unit="pcs"', false)->assertSee('data-unit="box"', false);
        $page->assertSee('id="f-color"', false);
    }
}
