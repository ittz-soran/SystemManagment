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
 * Two people pressing Delete on the same record.
 *
 * Found by Soran on the real system, 2026-09-12: two users deleted the same
 * invoice, the first saw "Sale deleted" and the second got a bare 404. The
 * data was right both times — one delete, one stock reversal — so nothing in
 * the suite had any reason to go red. What was wrong was the sentence.
 */
class AlreadyDeletedTest extends TestCase
{
    use RefreshDatabase;

    private function aSale(User $admin): Sale
    {
        $product = Product::create([
            'name' => 'X', 'sku' => 'X1',
            'category_id' => Category::create(['name' => 'C'])->id,
            'unit' => 'pcs', 'purchase_price' => 0, 'sale_price' => 1_000, 'quantity' => 0,
        ]);

        app(PurchaseService::class)->create(
            supplier: Supplier::create(['name' => 'S']),
            lines: [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 100]],
            user: $admin, purchaseDate: now(),
        );

        return app(SaleService::class)->create(
            customer: Customer::create(['name' => 'C']),
            lines: [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1_000]],
            user: $admin, saleDate: now(), amountPaid: 0,
        );
    }

    public function test_the_second_delete_is_told_what_happened_rather_than_shown_a_404(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $sale = $this->aSale($admin);

        $this->actingAs($admin)->delete(route('sales.destroy', $sale))->assertRedirect();

        $this->actingAs($admin)
            ->delete(route('sales.destroy', $sale))
            ->assertRedirect(route('sales.index'))
            ->assertSessionHas('warning');
    }

    /**
     * ⚠️ And an id that never existed still gets a 404.
     *
     * "Somebody else deleted this" would be an invented explanation, and the
     * whole value of the message is that it is true. This is the assertion that
     * stops the fix from turning every mistyped URL into a soothing lie.
     */
    public function test_something_that_never_existed_is_still_a_404(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('sales.destroy', 999999))
            ->assertNotFound();
    }

    /** A record still alive is not "already deleted" either — it is a 404. */
    public function test_a_live_record_reached_by_a_wrong_route_is_not_excused(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $sale = $this->aSale($admin);

        // A purchase route given a sale's id: nothing was deleted here.
        $this->actingAs($admin)
            ->delete(route('purchases.destroy', $sale->id + 10_000))
            ->assertNotFound();
    }

    /** The same hole was in all seventeen, so it is worth checking a second one. */
    public function test_it_covers_more_than_sales(): void
    {
        $this->seed();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        $supplier = Supplier::create(['name' => 'Gone']);

        $this->actingAs($admin)->delete(route('suppliers.destroy', $supplier))->assertRedirect();

        $this->actingAs($admin)
            ->delete(route('suppliers.destroy', $supplier))
            ->assertRedirect(route('suppliers.index'))
            ->assertSessionHas('warning');
    }
}
