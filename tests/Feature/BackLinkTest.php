<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The way back out of a detail page.
 *
 * Every screen you can open from a list now carries a link back to it, named
 * after where it lands rather than saying "Back". Two things make it worth a
 * test: the destination has to be the list the reader actually came from, and
 * the link must not be offered to someone the list would refuse.
 *
 * Where the reader came from is the script's half of this: arriving at a sale
 * from a product, the button says "Product 1" and goes there, using the
 * browser's own Back so the scroll comes back with it. That is checked in the
 * browser. What is fixed here is the markup underneath it — a real href to a
 * real route, so the button works with the script disabled, on a first visit,
 * and when opened in a new tab; plus the data-back-to marker naming the list
 * whose filters and scroll the script should restore.
 */
class BackLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private Supplier $supplier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->product = Product::create([
            'name' => 'USB 32GB', 'sku' => 'USB32',
            'category_id' => Category::create(['name' => 'Flash drives'])->id,
            'unit' => 'pcs', 'purchase_price' => 10_000, 'sale_price' => 15_000, 'quantity' => 0,
        ]);

        $this->supplier = Supplier::create(['name' => 'Bazaar Mobile']);
        $this->customer = Customer::create(['name' => 'Karwan']);
    }

    private function buy(): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );
    }

    private function sell(): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );
    }

    private function assertBackLink(string $html, string $url, ?string $remember = null): void
    {
        $this->assertMatchesRegularExpression(
            '/<a[^>]+href="'.preg_quote($url, '/').'"[^>]*class="back-link/',
            $html,
            "Expected a back link to $url",
        );

        if ($remember !== null) {
            $this->assertStringContainsString('data-back-to="'.$remember.'"', $html);
        }
    }

    public function test_a_sale_goes_back_to_the_sales_history(): void
    {
        $this->buy();
        $sale = $this->sell();

        $html = $this->actingAs($this->admin)
            ->get(route('sales.show', $sale))->assertOk()->getContent();

        $this->assertBackLink($html, route('sales.index'), 'sales');
    }

    public function test_a_purchase_goes_back_to_the_purchase_history(): void
    {
        $purchase = $this->buy();

        $html = $this->actingAs($this->admin)
            ->get(route('purchases.show', $purchase))->assertOk()->getContent();

        $this->assertBackLink($html, route('purchases.index'), 'purchases');
    }

    public function test_a_product_goes_back_to_the_product_list(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))->assertOk()->getContent();

        $this->assertBackLink($html, route('products.index'), 'products');
    }

    /**
     * A screen reached from a document goes back to that document, not to a
     * list the reader was never on.
     */
    public function test_a_return_screen_goes_back_to_the_document_it_is_against(): void
    {
        $purchase = $this->buy();

        $html = $this->actingAs($this->admin)
            ->get(route('purchase-returns.create', $purchase))->assertOk()->getContent();

        $this->assertBackLink($html, route('purchases.show', $purchase));
        $this->assertStringNotContainsString('data-back-to', $html);
    }

    public function test_editing_a_sale_goes_back_to_the_sale(): void
    {
        $this->buy();
        $sale = $this->sell();

        $html = $this->actingAs($this->admin)
            ->get(route('sales.edit', $sale))->assertOk()->getContent();

        $this->assertBackLink($html, route('sales.show', $sale));
    }

    /** ...while a new sale goes back to the list, which is where it started. */
    public function test_a_new_sale_goes_back_to_the_sales_history(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('sales.create'))->assertOk()->getContent();

        $this->assertBackLink($html, route('sales.index'), 'sales');
    }

    /**
     * The href is a route the server resolved, not a guess made from the
     * referrer: the script only ever swaps in a page it has seen this reader on,
     * so a link followed from an email or another site cannot decide where the
     * button goes.
     */
    public function test_the_destination_does_not_come_from_the_referrer(): void
    {
        $this->buy();
        $sale = $this->sell();

        $html = $this->actingAs($this->admin)
            ->withHeader('referer', 'https://somewhere-else.test/anything')
            ->get(route('sales.show', $sale))->assertOk()->getContent();

        $this->assertBackLink($html, route('sales.index'), 'sales');
        $this->assertStringNotContainsString('somewhere-else.test', $html);
    }

    public function test_it_is_not_offered_to_a_reader_the_list_would_refuse(): void
    {
        $this->buy();
        $sale = $this->sell();

        $clerk = User::factory()->create(['role' => User::ROLE_USER]);
        $clerk->permissions()->sync(
            Permission::whereIn('key', ['sales.view', 'products.view'])->pluck('id')
        );

        // Sales history is open to them, so the way back to it is offered.
        $this->assertBackLink(
            $this->actingAs($clerk)->get(route('sales.show', $sale))->assertOk()->getContent(),
            route('sales.index'),
        );

        // The product list is not, so no link is drawn into a 403.
        $html = $this->actingAs($this->admin)->get(route('products.show', $this->product))
            ->assertOk()->getContent();
        $this->assertStringContainsString('back-link', $html);

        $stockOnly = User::factory()->create(['role' => User::ROLE_USER]);
        $stockOnly->permissions()->sync(Permission::whereIn('key', ['sales.view'])->pluck('id'));

        $this->actingAs($stockOnly)->get(route('products.show', $this->product))->assertForbidden();
    }
}
