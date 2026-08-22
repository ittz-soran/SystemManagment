<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Following a reference to the document that made it.
 *
 * Three tables store a document as a type/id pair: the batches and movements
 * behind a product's FIFO trail, and the ledger rows behind a supplier or
 * customer balance. All three printed the raw pair — "Sale #12" — which is
 * where an investigation stopped, because there was nothing to click and the
 * id is not the number written on the paperwork.
 *
 * These are the screens someone opens when the stock or the balance looks
 * wrong, so each reference now carries the document's own number and a link to
 * it — except where there is no page to link to, or no permission to open it.
 */
class DocumentReferenceTest extends TestCase
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

    private function buy(int $quantity = 10, int $paid = 0): Purchase
    {
        return app(PurchaseService::class)->create(
            supplier: $this->supplier,
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: $paid,
        );
    }

    private function sell(int $quantity = 4): Sale
    {
        return app(SaleService::class)->create(
            customer: $this->customer,
            lines: [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );
    }

    private function adjust(): StockAdjustment
    {
        return app(StockAdjustmentService::class)->create(
            product: $this->product, direction: StockAdjustment::DIRECTION_IN,
            quantity: 5, reason: 'miscount', user: $this->admin, unitCost: 9_000,
        );
    }

    /** The document number, linked to the page that shows it. */
    private function assertLinks(string $html, string $url, string $label): void
    {
        $this->assertMatchesRegularExpression(
            '/<a[^>]+href="'.preg_quote($url, '/').'"[^>]*>\s*'.preg_quote($label, '/').'\s*</',
            $html,
            "Expected $label to link to $url",
        );
    }

    // ------------------------------------------------------- the FIFO trail

    public function test_a_batch_names_and_links_the_purchase_that_created_it(): void
    {
        $purchase = $this->buy();

        $html = $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))->assertOk()->getContent();

        $this->assertLinks($html, route('purchases.show', $purchase), $purchase->document_no);
        $this->assertStringNotContainsString('#'.$purchase->id.'</span>', $html);
    }

    public function test_every_movement_names_the_document_that_moved_the_stock(): void
    {
        $purchase = $this->buy();
        $sale = $this->sell();

        $saleReturn = app(SaleReturnService::class)->create(
            sale: $sale,
            lines: [['sale_item_id' => $sale->items()->firstOrFail()->id, 'quantity' => 1]],
            user: $this->admin, returnDate: now(),
        );

        $purchaseReturn = app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $purchase->items()->firstOrFail()->id, 'quantity' => 2]],
            user: $this->admin, returnDate: now(),
        );

        $html = $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))->assertOk()->getContent();

        $this->assertLinks($html, route('purchases.show', $purchase), $purchase->document_no);
        $this->assertLinks($html, route('sales.show', $sale), $sale->document_no);
        $this->assertLinks($html, route('sale-returns.show', $saleReturn), $saleReturn->document_no);
        $this->assertLinks($html, route('purchase-returns.show', $purchaseReturn), $purchaseReturn->document_no);
    }

    /**
     * An adjustment has no page of its own yet. Its number is still what the
     * reader needs to see, so it is printed — but not as a link into a 404.
     */
    public function test_an_adjustment_shows_its_number_without_a_link(): void
    {
        $adjustment = $this->adjust();

        $response = $this->actingAs($this->admin)
            ->get(route('products.show', $this->product))->assertOk();

        $response->assertSee($adjustment->document_no);
        $this->assertStringNotContainsString(
            '/stock-adjustments/'.$adjustment->id,
            $response->getContent(),
        );
    }

    // ---------------------------------------------------------- the ledgers

    public function test_a_supplier_statement_links_each_row_to_its_document(): void
    {
        $purchase = $this->buy(10, paid: 40_000);

        $html = $this->actingAs($this->admin)
            ->get(route('suppliers.show', $this->supplier))->assertOk()->getContent();

        $this->assertLinks($html, route('purchases.show', $purchase), $purchase->document_no);
    }

    public function test_a_customer_statement_links_each_row_to_its_document(): void
    {
        $this->buy();
        $sale = $this->sell();

        $html = $this->actingAs($this->admin)
            ->get(route('customers.show', $this->customer))->assertOk()->getContent();

        $this->assertLinks($html, route('sales.show', $sale), $sale->document_no);
    }

    /**
     * The note is the only place a row says *why* it exists — "Reversal of
     * PUR-00001", a cashier's own words on a payment — so linking the document
     * must not swallow it.
     */
    public function test_a_note_that_says_more_than_the_number_is_kept(): void
    {
        $purchase = $this->buy(10);

        $return = app(PurchaseReturnService::class)->create(
            purchase: $purchase,
            lines: [['purchase_item_id' => $purchase->items()->firstOrFail()->id, 'quantity' => 3]],
            user: $this->admin, returnDate: now(),
        );

        // Undoing the return posts a row whose note is the only thing that says
        // what happened — the reference alone would read as a second credit.
        app(PurchaseReturnService::class)->delete($return, $this->admin);

        $response = $this->actingAs($this->admin)
            ->get(route('suppliers.show', $this->supplier))->assertOk();

        $response->assertSee(__('Reversal of :document', ['document' => $return->document_no]));
    }

    /** ...while a note that only repeats the document number is not printed twice. */
    public function test_a_note_that_only_repeats_the_number_is_not_shown_twice(): void
    {
        $purchase = $this->buy(10);

        $html = $this->actingAs($this->admin)
            ->get(route('suppliers.show', $this->supplier))->assertOk()->getContent();

        $this->assertSame(
            1,
            substr_count($html, $purchase->document_no),
            'The purchase row should name PUR-00001 once, not as both a link and a note',
        );
    }

    // ------------------------------------------------------------ permission

    public function test_a_reader_without_the_permission_gets_the_number_as_text(): void
    {
        $purchase = $this->buy();

        $clerk = User::factory()->create(['role' => User::ROLE_USER]);
        $clerk->permissions()->sync(Permission::whereIn('key', ['products.view'])->pluck('id'));

        $response = $this->actingAs($clerk)
            ->get(route('products.show', $this->product))->assertOk();

        $response->assertSee($purchase->document_no);
        $this->assertStringNotContainsString(
            'href="'.route('purchases.show', $purchase).'"',
            $response->getContent(),
        );
    }
}
