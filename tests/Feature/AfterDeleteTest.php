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
 * Where you land after deleting a document.
 *
 * Deleting from a list leaves the list standing, so the reader stays on it.
 * Deleting from the document's own page is the awkward one: that page has just
 * stopped existing, and the list it belonged to is a guess — opening an invoice
 * from the second-hand book and deleting it should not land on the sales
 * history. The form carries the page behind it, and that is where they go.
 */
class AfterDeleteTest extends TestCase
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
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'Bazaar Mobile']),
            lines: [['product_id' => $this->product->id, 'quantity' => 20, 'unit_price' => 10_000]],
            user: $this->admin, purchaseDate: now(), amountPaid: 0,
        );
    }

    private function sale(): Sale
    {
        return app(SaleService::class)->create(
            customer: Customer::create(['name' => 'Karwan '.uniqid()]),
            lines: [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 15_000]],
            user: $this->admin, saleDate: now(), amountPaid: 0,
        );
    }

    /** The case from the shop: an invoice opened out of the second-hand book. */
    public function test_deleting_from_a_document_page_returns_to_where_it_was_opened_from(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->admin)
            ->from(route('sales.show', $sale))
            ->delete(route('sales.destroy', $sale), ['return_to' => '/second-hand'])
            ->assertRedirect('/second-hand');
    }

    /** Deleting from the list leaves the list standing, filters and all. */
    public function test_deleting_from_a_list_stays_on_the_list(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->admin)
            ->from(route('sales.index', ['page' => 2]))
            ->delete(route('sales.destroy', $sale), ['return_to' => '/second-hand'])
            ->assertRedirect(route('sales.index', ['page' => 2]));
    }

    /** With nothing carried, the list the document belonged to. */
    public function test_it_falls_back_to_the_list(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->admin)
            ->from(route('sales.show', $sale))
            ->delete(route('sales.destroy', $sale))
            ->assertRedirect(route('sales.index'));
    }

    /**
     * The carried value comes from the page, so it is treated as something a
     * visitor typed. A redirect is a place the shop sends its own staff; it is
     * not somewhere an outside site gets to choose.
     */
    public function test_it_refuses_to_be_sent_off_the_site(): void
    {
        foreach (['https://evil.test/steal', '//evil.test/steal', 'javascript:alert(1)', '\\\\evil.test'] as $hostile) {
            $sale = $this->sale();

            $this->actingAs($this->admin)
                ->from(route('sales.show', $sale))
                ->delete(route('sales.destroy', $sale), ['return_to' => $hostile])
                ->assertRedirect(route('sales.index'));
        }
    }

    /** And never back to the page that has just stopped existing. */
    public function test_it_never_returns_to_the_deleted_page(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->admin)
            ->from(route('sales.show', $sale))
            ->delete(route('sales.destroy', $sale), [
                'return_to' => parse_url(route('sales.show', $sale), PHP_URL_PATH),
            ])
            ->assertRedirect(route('sales.index'));
    }
}
