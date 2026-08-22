<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Getting from a document to its returns and back.
 *
 * A purchase lists the returns made against it and a return names the purchase
 * it came from, but until now both were plain text, so reading one meant
 * hunting for the other through the returns list. Each number is a link to the
 * document it names — unless the reader lacks permission to open it, in which
 * case the number stays, because it is still information.
 */
class ReturnNavigationTest extends TestCase
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

    private function returnToSupplier(Purchase $purchase): PurchaseReturn
    {
        return app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $purchase->items()->firstOrFail()->id, 'quantity' => 3]],
            user: $this->admin, returnDate: now(),
        );
    }

    private function returnFromCustomer(Sale $sale): SaleReturn
    {
        return app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->admin, returnDate: now(),
        );
    }

    /** A link is the number wrapped in an anchor pointing at the document. */
    private function assertLinks(string $html, string $url, string $documentNo): void
    {
        $this->assertMatchesRegularExpression(
            '/<a[^>]+href="'.preg_quote($url, '/').'"[^>]*>\s*'.preg_quote($documentNo, '/').'\s*</',
            $html,
            "Expected $documentNo to link to $url",
        );
    }

    // ------------------------------------------------- purchase <-> its return

    public function test_a_purchase_links_to_the_returns_made_against_it(): void
    {
        $purchase = $this->buy();
        $return = $this->returnToSupplier($purchase);

        $response = $this->actingAs($this->admin)->get(route('purchases.show', $purchase))->assertOk();

        $this->assertLinks(
            $response->getContent(),
            route('purchase-returns.show', $return),
            $return->document_no,
        );
    }

    public function test_a_purchase_return_links_back_to_its_purchase(): void
    {
        $purchase = $this->buy();
        $return = $this->returnToSupplier($purchase);

        $response = $this->actingAs($this->admin)
            ->get(route('purchase-returns.show', $return))->assertOk();

        $this->assertLinks(
            $response->getContent(),
            route('purchases.show', $purchase),
            $purchase->document_no,
        );
    }

    // ----------------------------------------------------- sale <-> its return

    public function test_a_sale_links_to_the_returns_made_against_it(): void
    {
        $this->buy();
        $sale = $this->sell();
        $return = $this->returnFromCustomer($sale);

        $response = $this->actingAs($this->admin)->get(route('sales.show', $sale))->assertOk();

        $this->assertLinks(
            $response->getContent(),
            route('sale-returns.show', $return),
            $return->document_no,
        );
    }

    public function test_a_sale_return_links_back_to_its_sale(): void
    {
        $this->buy();
        $sale = $this->sell();
        $return = $this->returnFromCustomer($sale);

        $response = $this->actingAs($this->admin)
            ->get(route('sale-returns.show', $return))->assertOk();

        $this->assertLinks(
            $response->getContent(),
            route('sales.show', $sale),
            $sale->document_no,
        );
    }

    // ------------------------------------------------------------- permissions

    public function test_the_number_survives_without_the_permission_to_open_it(): void
    {
        $purchase = $this->buy();
        $return = $this->returnToSupplier($purchase);

        $clerk = User::factory()->create(['role' => User::ROLE_USER]);
        $clerk->permissions()->sync(Permission::whereIn('key', ['purchases.view'])->pluck('id'));

        $response = $this->actingAs($clerk)->get(route('purchases.show', $purchase))->assertOk();

        $response->assertSee($return->document_no);
        $this->assertStringNotContainsString(
            'href="'.route('purchase-returns.show', $return).'"',
            $response->getContent(),
            'A reader who cannot open the return must not be offered a link into a 403',
        );
    }
}
