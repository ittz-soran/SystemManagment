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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Government customers, and their A5 invoice — PROJECT_DOC Section 9.
 *
 * Soran, 2026-10-20: *"goverment customers (i want show as deferet list in
 * customers) like "بەڕێوەبەرایەتی پۆلیسی پاریزگای ‌هەڵەبجە" and this type need
 * A5 invoice"* — laid out as the legal invoice book he sent the same day, and
 * nothing added to it: *"Use my uploaded invoice without change anything,
 * because already have aria to signurate and stamp"*.
 */
class GovernmentCustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private const POLICE = 'بەڕێوەبەرایەتی پۆلیسی پاریزگای هەڵەبجە';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function department(): Customer
    {
        return Customer::create(['name' => self::POLICE, 'type' => Customer::GOVERNMENT, 'address' => 'Halabja']);
    }

    private function saleTo(Customer $customer, int $lines = 1): Sale
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

        return app(SaleService::class)->create(
            customer: $customer, lines: $items, user: $this->admin, saleDate: now(), amountPaid: 0,
        );
    }

    // ---- three kinds of customer -------------------------------------------

    public function test_every_customer_is_normal_until_said_otherwise(): void
    {
        $this->actingAs($this->admin)->postJson(route('customers.store'), ['name' => 'Rebaz'])->assertCreated();

        $this->assertSame(Customer::NORMAL, Customer::where('name', 'Rebaz')->sole()->type);
        $this->assertFalse(Customer::cashCustomer()->isGovernment());
    }

    /** The Cash Customer is never government, whatever its row says. */
    public function test_the_cash_customer_is_never_government(): void
    {
        $cash = Customer::cashCustomer();
        $cash->forceFill(['type' => Customer::GOVERNMENT])->save();

        $this->assertFalse($cash->fresh()->isGovernment());
    }

    public function test_a_department_is_added_as_government(): void
    {
        $this->actingAs($this->admin)->post(route('customers.store'), [
            'name' => self::POLICE, 'type' => Customer::GOVERNMENT,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Customer::where('name', self::POLICE)->sole()->isGovernment());
    }

    public function test_a_type_that_is_neither_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('customers.store'), ['name' => 'X', 'type' => 'vip'])
            ->assertSessionHasErrors('type');
    }

    /** Saving without the box — the cart's quick add — leaves the type alone. */
    public function test_an_edit_without_a_type_keeps_it(): void
    {
        $police = $this->department();

        $this->actingAs($this->admin)->put(route('customers.update', $police), ['name' => self::POLICE, 'phone' => '0750'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($police->fresh()->isGovernment());

        $this->actingAs($this->admin)->put(route('customers.update', $police), ['name' => self::POLICE, 'type' => Customer::NORMAL]);

        $this->assertFalse($police->fresh()->isGovernment());
    }

    /**
     * The rows of the list, and only the rows: the page also carries every
     * customer's name for the name box's suggestions, wherever the list is.
     */
    private function listed(array $query = []): string
    {
        $html = $this->actingAs($this->admin)->get(route('customers.index', $query))->assertOk()->getContent();
        $start = strpos($html, '<tbody>');

        return substr($html, $start, strpos($html, '</tbody>') - $start);
    }

    public function test_government_customers_are_their_own_list(): void
    {
        $this->department();
        Customer::create(['name' => 'Rebaz']);

        $everybody = $this->listed();
        $this->assertStringContainsString('Rebaz', $everybody);
        $this->assertStringContainsString(__('Cash Customer'), $everybody);
        $this->assertStringNotContainsString(self::POLICE, $everybody);

        $government = $this->listed(['type' => Customer::GOVERNMENT]);
        $this->assertStringContainsString(self::POLICE, $government);
        $this->assertStringNotContainsString('Rebaz', $government);
        $this->assertStringNotContainsString(__('Cash Customer'), $government);
    }

    // ---- the A5 invoice ----------------------------------------------------

    public function test_a_government_sale_prints_on_a5_laid_out_as_the_book(): void
    {
        Setting::put('shop_name', 'Soran Store');
        Setting::put('shop_name_ku', 'سۆران ستۆر');
        Setting::put('shop_website', 'www.soranstore.com');
        Setting::put('invoice_tagline', "بۆ ئەلیکترۆنیات و پرۆگرامسازی\nfor electronics and programming");

        $sale = $this->saleTo($this->department());

        $this->actingAs($this->admin)->get(route('sales.print', $sale))
            ->assertOk()
            ->assertSee('size: A5 portrait', false)
            ->assertSee(self::POLICE)
            ->assertSee('Mr./ M/s.')
            ->assertSee('بەڕێز/ السيد')
            ->assertSee('Content')
            ->assertSee('The Total')
            ->assertSee('Fault goes back to both sides.')
            ->assertDontSee('Received by')
            ->assertDontSee('Stamp')
            ->assertSee('Inv. No. : '.$sale->document_no)
            ->assertSee('سۆران ستۆر')
            ->assertSee('for electronics and programming')
            ->assertSee('www.soranstore.com')
            ->assertSee('70,000');
    }

    /**
     * The total in words sits in the total band, in Kurdish after تەنها, as
     * Soran writes it on the book — whatever language the screen is in, and
     * without leaving the screen in Kurdish afterwards.
     */
    public function test_the_total_is_written_in_kurdish_words_in_the_total_band(): void
    {
        app()->setLocale('en');
        $sale = $this->saleTo($this->department());
        $sale->forceFill(['total_amount' => 25_000])->save();

        $html = $this->actingAs($this->admin)->get(route('sales.print', $sale))->assertOk()->getContent();
        $tfoot = substr($html, strpos($html, '<tfoot>'), strpos($html, '</tfoot>') - strpos($html, '<tfoot>'));

        $this->assertStringContainsString('تەنها بیست و پێنج هەزار دینار', $tfoot);
        $this->assertStringContainsString('The Total', $tfoot);
        $this->assertSame('en', app()->getLocale());
    }

    /** The book has ten ruled lines, and a short invoice still looks like it. */
    public function test_a_short_invoice_is_padded_to_ten_lines(): void
    {
        $sale = $this->saleTo($this->department(), lines: 3);

        $html = $this->actingAs($this->admin)->get(route('sales.print', $sale))->getContent();
        $tbody = substr($html, strpos($html, '<tbody>'), strpos($html, '</tbody>') - strpos($html, '<tbody>'));

        $this->assertSame(10, substr_count($tbody, '<tr'));
    }

    public function test_everybody_else_still_gets_the_ordinary_invoice(): void
    {
        $sale = $this->saleTo(Customer::create(['name' => 'Rebaz']));

        $this->actingAs($this->admin)->get(route('sales.print', $sale))
            ->assertOk()
            ->assertDontSee('size: A5', false)
            ->assertDontSee('Fault goes back to both sides.');
    }

    public function test_the_ordinary_invoice_is_one_link_away(): void
    {
        $sale = $this->saleTo($this->department());

        $this->actingAs($this->admin)->get(route('sales.print', $sale))
            ->assertSee(route('sales.print', ['sale' => $sale, 'paper' => 'a4']), false);

        $this->actingAs($this->admin)->get(route('sales.print', ['sale' => $sale, 'paper' => 'a4']))
            ->assertOk()
            ->assertDontSee('size: A5', false);
    }

    /**
     * ⚠️ The letter م is two bytes in UTF-8 and the second is 0x85, which a
     * line break pattern without /u treats as a new line — cutting the
     * tagline's Kurdish and Arabic words in two on the printed page.
     */
    public function test_the_tagline_keeps_every_letter_of_kurdish_and_arabic(): void
    {
        Setting::put('invoice_tagline', "بۆ ئەلیکترۆنیات و پرۆگرامسازی\nللإلكترونيات والبرمجة");

        $html = $this->actingAs($this->admin)->get(route('sales.print', $this->saleTo($this->department())))->getContent();

        $this->assertStringContainsString('پرۆگرامسازی', $html);
        $this->assertStringContainsString('للإلكترونيات والبرمجة', $html);
        $this->assertStringNotContainsString("\u{FFFD}", $html);
    }

    public function test_the_tagline_is_a_setting(): void
    {
        $form = $this->actingAs($this->admin)->get(route('settings.edit'))->assertOk();
        $form->assertSee('name="invoice_tagline"', false);
    }

    public function test_the_customer_page_says_its_invoices_print_on_a5(): void
    {
        $this->actingAs($this->admin)->get(route('customers.show', $this->department()))
            ->assertOk()
            ->assertSee(__('Government customer — their sale invoices print on A5.'));
    }
}
