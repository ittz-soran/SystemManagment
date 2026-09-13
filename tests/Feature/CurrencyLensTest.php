<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading a screen's figures in another currency — Section 2b, decided with
 * Soran on 2026-09-13.
 *
 * A currency is a LENS, not a second set of books: nothing foreign is stored,
 * and a screen only converts because it asked to. The tests that matter here
 * are the ones about where the lens may NOT go, and about it being honest that
 * a converted figure is an estimate.
 */
class CurrencyLensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->trade();
    }

    private function usd(): Currency
    {
        return Currency::where('code', 'USD')->firstOrFail();
    }

    /**
     * A second reader, looking through a lens.
     *
     * ⚠️ A separate instance, not `$this->admin` with a field changed.
     * `forceFill` mutates in place, so setting the lens on the shared admin
     * quietly put every later "and without a lens…" assertion in the same test
     * behind the lens too — which is how the first version of this file passed
     * one assertion it should have failed.
     */
    private function looking(string $code): User
    {
        User::whereKey($this->admin->getKey())->update(['display_currency' => $code]);

        return User::findOrFail($this->admin->getKey());
    }

    /** The same person, read fresh, with whatever the database now says. */
    private function reader(): User
    {
        return User::findOrFail($this->admin->getKey());
    }

    /** A purchase and a sale, so the report has figures to convert. */
    private function trade(): void
    {
        $product = Product::create([
            'name' => 'Cable', 'sku' => 'C1', 'unit' => 'pcs',
            'category_id' => Category::create(['name' => 'Wires'])->id,
            'purchase_price' => 0, 'sale_price' => 10_000, 'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S']),
            lines: [['product_id' => $product->id, 'quantity' => 100, 'unit_price' => 6_600]],
            user: $this->admin, purchaseDate: today()->subDays(2),
        );

        app(SaleService::class)->create(
            customer: Customer::create(['name' => 'C']),
            lines: [['product_id' => $product->id, 'quantity' => 100, 'unit_price' => 13_200]],
            user: $this->admin, saleDate: today(),
        );
    }

    // ------------------------------------------------------------ the reading

    public function test_a_report_reads_in_the_chosen_currency(): void
    {
        // 1,320,000 dinars of revenue is $1,000 at 1,320.
        // 1,320,000 dinars of revenue is $1,000 at 1,320.
        $this->actingAs($this->reader())->get(route('reports.index'))
            ->assertOk()
            ->assertSee('1,320,000');

        $this->actingAs($this->looking('USD'))->get(route('reports.index'))
            ->assertOk()
            ->assertSee('1,000')
            ->assertDontSee('1,320,000');
    }

    /**
     * ⚠️ A converted figure is an ESTIMATE and the page has to say so.
     *
     * The books are in dinars; this is those dinars divided by today's rate,
     * and today's rate is not the rate anything was recorded at. A dollar total
     * with no such sentence is a figure somebody quotes to a supplier.
     */
    public function test_a_converted_page_says_what_it_is(): void
    {
        $page = $this->actingAs($this->looking('USD'))->get(route('reports.index'))->assertOk();

        $page->assertSee(__('Shown in :code at today’s rate of :rate — an estimate. The books are kept in :base and nothing here is what was recorded.', [
            'code' => 'USD', 'rate' => '1320', 'base' => 'IQD',
        ]));

        // And it does not appear when there is nothing to disclaim.
        User::whereKey($this->admin->getKey())->update(['display_currency' => null]);

        $this->actingAs($this->reader())->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee('an estimate');
    }

    // ------------------------------------------------ where it may NOT go

    /**
     * ⚠️ THE RULE THIS DESIGN TURNS ON — Soran's decision 3b.
     *
     * You buy in dollars; you sell across a counter for cash in dinars. A till
     * showing $38.50 while a customer hands over notes is the one place a wrong
     * number costs money in the same minute.
     *
     * The lens reaches a screen only because that screen passed a currency to
     * `money()`. This asserts the sale screen never does, with the reader's
     * preference set as strongly as it can be.
     */
    public function test_the_till_never_reads_in_another_currency(): void
    {
        $user = $this->looking('USD');

        // No switch and no estimate note: the sale screen never offers a lens,
        // however strongly the reader's preference is set.
        $this->actingAs($user)->get(route('sales.create'))
            ->assertOk()
            ->assertDontSee(__('Read in'))
            ->assertDontSee('an estimate');

        /*
         * And the prices the till actually draws — which arrive as JSON from
         * the search box rather than in the page, so this is where a converted
         * figure would really reach a customer.
         */
        $this->actingAs($user)->getJson(route('products.search', ['q' => 'Cable']))
            ->assertOk()
            ->assertJsonPath('products.0.sale_price', 10_000);

        $this->actingAs($user)->get(route('sales.index'))
            ->assertOk()
            ->assertSee('1,320,000');
    }

    /** A printed invoice is not the reader's preference either. */
    public function test_a_printed_invoice_is_not_converted(): void
    {
        $sale = Sale::firstOrFail();

        $this->actingAs($this->looking('USD'))->get(route('sales.print', $sale))
            ->assertOk()
            ->assertSee('1,320,000');
    }

    // ------------------------------------------------------ choosing one

    public function test_the_choice_is_remembered_for_the_person_who_made_it(): void
    {
        $this->actingAs($this->admin)
            ->post(route('preferences.currency'), ['display_currency' => 'USD'])
            ->assertRedirect();

        $this->assertSame('USD', $this->admin->fresh()->display_currency);

        // And another person is untouched by it.
        $other = User::create([
            'name' => 'Till', 'email' => 'till@example.com',
            'password' => 'a-strong-password-2026', 'role' => User::ROLE_ADMIN,
            'is_active' => true, 'language' => 'en', 'theme' => 'auto', 'items_per_page' => 25,
        ]);

        $this->assertNull($other->lens());
    }

    /** Choosing the shop's own currency is choosing no lens at all. */
    public function test_the_base_currency_is_stored_as_no_lens(): void
    {
        $this->actingAs($this->looking('USD'))
            ->post(route('preferences.currency'), ['display_currency' => 'IQD'])
            ->assertRedirect();

        $this->assertNull($this->admin->fresh()->display_currency);
        $this->assertNull($this->admin->fresh()->lens());
    }

    /**
     * ⚠️ A currency switched off takes every reader off it.
     *
     * A rate nobody maintains is a rate that quietly goes wrong, and a reader
     * left on one would go on quoting figures from it for months.
     */
    public function test_switching_a_currency_off_returns_its_readers_to_the_base(): void
    {
        $user = $this->looking('USD');
        $this->assertSame('USD', $user->lens()?->code);

        $this->usd()->update(['is_active' => false]);
        Currency::flushCache();

        $this->assertNull($user->fresh()->lens(), 'A reader was left on a currency the shop stopped keeping.');

        $this->actingAs($user->fresh())->get(route('reports.index'))
            ->assertOk()
            ->assertSee('1,320,000');
    }

    public function test_a_currency_the_shop_does_not_have_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('preferences.currency'), ['display_currency' => 'XYZ'])
            ->assertSessionHasErrors('display_currency');

        $this->assertNull($this->admin->fresh()->display_currency);
    }

    /** The switch is furniture when the shop keeps only its own currency. */
    public function test_the_switch_is_hidden_when_there_is_nothing_to_switch_to(): void
    {
        $this->actingAs($this->reader())->get(route('reports.index'))
            ->assertOk()
            ->assertSee(__('Read in'));

        $this->usd()->update(['is_active' => false]);
        Currency::flushCache();

        $this->actingAs($this->reader())->get(route('reports.index'))
            ->assertOk()
            ->assertDontSee(__('Read in'));
    }

    /** ⚠️ Whatever a screen shows, the database is untouched by looking at it. */
    public function test_looking_through_a_lens_writes_nothing(): void
    {
        $before = Sale::firstOrFail()->total_amount;

        $this->actingAs($this->looking('USD'))->get(route('reports.index'))->assertOk();
        $this->actingAs($this->looking('USD'))->get(route('sales.index'))->assertOk();

        $this->assertSame($before, Sale::firstOrFail()->total_amount);
        $this->assertSame('IQD', Money::base()->code);
    }

    // -------------------------------------------------- the other two screens

    public function test_the_dashboard_reads_in_the_chosen_currency(): void
    {
        // The shelf is 100 units at 6,600 = 660,000 dinars, which is $500.
        $this->actingAs($this->reader())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('1,320,000');

        $this->actingAs($this->looking('USD'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('1,000')
            ->assertSee('an estimate')
            ->assertDontSee('1,320,000');
    }

    public function test_the_products_list_reads_in_the_chosen_currency(): void
    {
        // The product's own sale price, 10,000 dinars, is $7.58 at 1,320.
        $this->actingAs($this->reader())->get(route('products.index'))
            ->assertOk()
            ->assertSee('10,000');

        $this->actingAs($this->looking('USD'))->get(route('products.index'))
            ->assertOk()
            ->assertSee('7.58')
            ->assertSee('an estimate');
    }

    /**
     * ⚠️ A partially loaded reader must not take a screen down.
     *
     * Eloquent runs strictly here, so reading a column that was never selected
     * throws. A User built without `display_currency` — a partial select, a row
     * still in memory from `create()` — would 500 every screen that draws a
     * figure. No preference recorded is no lens, which is the same answer.
     */
    public function test_a_reader_whose_row_was_never_fully_loaded_has_no_lens(): void
    {
        $partial = User::query()->select('id', 'name', 'email', 'role', 'is_active')
            ->whereKey($this->admin->getKey())->firstOrFail();

        $this->assertNull($partial->lens());
    }
}
