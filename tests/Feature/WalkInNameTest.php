<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A name written on a walk-in sale — Soran, 2026-09-27.
 *
 * *"sometimes i need have name customer or person name … this is not stored
 * customers -> just like an note for invoices"*.
 *
 * ⚠️ **It is a note, not a customer, and that is the whole feature.** A
 * `customers` row for every walk-in would fill the list with people who bought
 * one cable, each carrying a balance the shop must reconcile forever.
 */
class WalkInNameTest extends TestCase
{
    use RefreshDatabase;

    private Product $pd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->pd = Product::create([
            'name' => 'Cable 2A', 'kind' => Product::KIND_STOCK, 'sku' => 'CX-2A',
            'barcode' => 'CX2A', 'category_id' => Category::first()->id,
            'unit' => 'pcs', 'purchase_price' => 2_000, 'sale_price' => 5_000, 'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar', 'phone' => '0770', 'is_active' => true]),
            lines: [['product_id' => $this->pd->id, 'quantity' => 50, 'unit_price' => 2_000]],
            user: $this->user(), purchaseDate: today()->subDay(), amountPaid: 100_000,
        );
    }

    private function user(): User
    {
        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    private function sell(Customer $customer, ?string $walkIn, int $paid = 10_000): Sale
    {
        return app(SaleService::class)->create(
            customer: $customer,
            lines: [['product_id' => $this->pd->id, 'quantity' => 2, 'unit_price' => 5_000]],
            user: $this->user(), saleDate: today(),
            amountPaid: $paid, paymentMethod: 'cash', walkInName: $walkIn,
        );
    }

    public function test_a_walk_in_name_is_written_on_the_sale_and_creates_no_customer(): void
    {
        $before = Customer::count();

        $sale = $this->sell(Customer::cashCustomer(), 'Ahmed the plumber');

        $this->assertSame('Ahmed the plumber', $sale->walk_in_name);
        $this->assertSame($before, Customer::count(), 'a walk-in name must not become a customer');
        $this->assertTrue($sale->customer->is_system, 'the sale is still to the Cash Customer');
    }

    /**
     * ⚠️ **Beside the account, never instead of it.** The sale really was to
     * the Cash Customer and that is whose balance it touched; a document
     * showing only the name would misrepresent the books.
     */
    public function test_the_document_shows_both_the_account_and_the_name(): void
    {
        $sale = $this->sell(Customer::cashCustomer(), 'Ahmed');

        $this->assertSame(__('Cash Customer').' · Ahmed', $sale->soldTo());

        $this->actingAs($this->user())->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('Ahmed')
            ->assertSee(__('Cash Customer'));

        $this->actingAs($this->user())->get(route('sales.print', $sale))
            ->assertOk()
            ->assertSee('Ahmed');
    }

    /**
     * ⚠️ **The server decides, not the form.** The tick box is hidden for a
     * named customer, but a hidden field is a suggestion — anything can post
     * anything, and two names on one document is two answers to one question.
     */
    public function test_a_named_customer_never_carries_a_walk_in_name(): void
    {
        $karwan = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        $sale = $this->sell($karwan, 'Somebody else', paid: 10_000);

        $this->assertNull($sale->walk_in_name);
        $this->assertSame('Karwan', $sale->soldTo());
    }

    /** And posting one straight at the route is refused the same way. */
    public function test_the_route_drops_it_for_a_named_customer_too(): void
    {
        $karwan = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        $this->actingAs($this->user())->post(route('sales.store'), [
            'customer_id' => $karwan->id,
            'walk_in_name' => 'Nice try',
            'sale_date' => today()->toDateString(),
            'amount_paid' => 10_000,
            'payment_method' => 'cash',
            'lines' => [['product_id' => $this->pd->id, 'quantity' => 2, 'unit_price' => 5_000]],
        ])->assertRedirect();

        $this->assertNull(Sale::latest('id')->first()->walk_in_name);
    }

    /** ⚠️ Whitespace is not a name. */
    public function test_a_blank_name_is_stored_as_nothing(): void
    {
        $sale = $this->sell(Customer::cashCustomer(), '   ');

        $this->assertNull($sale->walk_in_name);
        $this->assertSame(__('Cash Customer'), $sale->soldTo());
    }

    /**
     * ⚠️ **Re-read on every edit.** Left out of the update, a name would
     * survive a change of customer — so an invoice moved onto a named account
     * would keep somebody else's name written on it.
     */
    public function test_moving_the_sale_to_a_named_customer_takes_the_name_off(): void
    {
        $sale = $this->sell(Customer::cashCustomer(), 'Ahmed');
        $karwan = Customer::create(['name' => 'Karwan', 'phone' => '0750']);

        app(SaleService::class)->update(
            sale: $sale,
            customer: $karwan,
            lines: [['product_id' => $this->pd->id, 'quantity' => 2, 'unit_price' => 5_000]],
            user: $this->user(), saleDate: today(),
        );

        $this->assertNull($sale->fresh()->walk_in_name);
    }

    /** ⚠️ "Who bought one of these" is what the search box is for. */
    public function test_the_sales_list_finds_a_sale_by_the_name_on_it(): void
    {
        $found = $this->sell(Customer::cashCustomer(), 'Ahmed the plumber');
        $other = $this->sell(Customer::cashCustomer(), null);

        $this->actingAs($this->user())->get(route('sales.index', ['search' => 'plumber']))
            ->assertOk()
            ->assertSee($found->document_no)
            ->assertDontSee($other->document_no);
    }

    // ---- The form -----------------------------------------------------------

    /** The tick and its box are on the till, and the box starts hidden. */
    public function test_the_till_offers_the_tick(): void
    {
        $this->actingAs($this->user())->get(route('sales.create'))
            ->assertOk()
            ->assertSee(__('Write a name on this invoice'))
            ->assertSee('id="walk-in-toggle"', false)
            ->assertSee('name="walk_in_name"', false)
            ->assertSee(__('Written on the invoice only. It does not create a customer and nothing is owed.'));
    }

    /** ⚠️ And an edit reopens with the name already in the box. */
    public function test_editing_a_sale_brings_its_name_back(): void
    {
        $sale = $this->sell(Customer::cashCustomer(), 'Ahmed');

        $this->actingAs($this->user())->get(route('sales.edit', $sale))
            ->assertOk()
            ->assertSee('value="Ahmed"', false);
    }
}
